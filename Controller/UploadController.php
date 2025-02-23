<?php

namespace Comur\ImageBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

use Symfony\Component\Finder\Finder;

use Comur\ImageBundle\Handler\UploadHandler;

class UploadController extends AbstractController
{
    /**
     * Save uploaded image according to comur_image field configuration
     *
     * @param Request $request
     */
    public function uploadImageAction(Request $request, string $thumbsDir, string $mediaLibThumbSize, string $publicDir)
    {
        $config = json_decode($request->request->get('config'),true);

        $uploadUrl = $publicDir . '/' . $config['uploadConfig']['uploadDir'];
        $uploadUrl = substr($uploadUrl, -strlen('/')) === '/' ? $uploadUrl : $uploadUrl . '/';

        // We must use a streamed response because the UploadHandler echoes directly
        $response = new StreamedResponse();

        if ($config['uploadConfig']['generateFilename']) 
        {
            $filename = sha1(uniqid(mt_rand(), true));
        }
        else 
        {
            $filename = $request->files->get('image_upload_file')->getClientOriginalName();
            if(file_exists($uploadUrl.$thumbsDir.'/'.$filename))
            {
                $filename = time().'-'.$filename;
            }
        }

        $ext = $request->files->get('image_upload_file')->getClientOriginalExtension();
        $completeName = $filename.'.'.$ext;
        $controller = $this;

        $handlerConfig = array(
            'upload_dir' => $uploadUrl,
            'param_name' => 'image_upload_file',
            'file_name' => $filename,
            'generated_file_name' => $config['uploadConfig']['generateFilename'],
            'upload_url' => $config['uploadConfig']['webDir'],
            'min_width' => $config['cropConfig']['minWidth'],
            'min_height' => $config['cropConfig']['minHeight'],
            'image_versions' => array(
                'thumbnail' => array(
                    'upload_dir' => $uploadUrl.$thumbsDir.'/',
                    'upload_url' => $config['uploadConfig']['webDir'].'/'.$thumbsDir.'/',
                    'crop' => true,
                    'max_width' => $mediaLibThumbSize,
                    'max_height' => $mediaLibThumbSize
                )
            )
        );
        
        $errorMessages = array(
            1 => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
            2 => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form',
            3 => 'The uploaded file was only partially uploaded',
            4 => 'No file was uploaded',
            6 => 'Missing a temporary folder',
            7 => 'Failed to write file to disk',
            8 => 'A PHP extension stopped the file upload',
            'post_max_size' => 'The uploaded file exceeds the post_max_size directive in php.ini',
            'max_file_size' => 'File is too big',
            'min_file_size' => 'File is too small',
            'accept_file_types' => 'Filetype not allowed',
            'max_number_of_files' => 'Maximum number of files exceeded',
            'max_width' => 'Image exceeds maximum width',
            'min_width' => "Image requires a minimum width ($config[cropConfig][minWidth])",
            'max_height' => 'Image exceeds maximum height',
            'min_height' => "Image requires a minimum height ($config[cropConfig][minHeight])",
            'abort' => 'File upload aborted',
            'image_resize' => 'Failed to resize image',
        );

        $response->setCallback(function () use($handlerConfig, $errorMessages) {
            new UploadHandler($handlerConfig, true, $errorMessages);
        });

        return $response;
    }

    /**
     * Crop image using jCrop and upload config parameters and create thumbs if needed
     *
     * @param Request $request
     */
    public function cropImageAction(Request $request, string $galleryDir, string $galleryThumbSize, string $croppedImageDir, string $publicDir, string $thumbsDir)
    {
        $config = json_decode($request->request->get('config'),true);
        $params = $request->request->all();
        
        $x = (int) round($params['x']);
        $y = (int) round($params['y']);
        $w = (int) round($params['w']);
        $h = (int) round($params['h']);
        $tarW = (int) round($config['cropConfig']['minWidth']);
        $tarH = (int) round($config['cropConfig']['minHeight']);

        //Issue 36
        if($x < 0)
        {
            $w = $w + $x;
            $x = 0;
        }

        if($y < 0)
        {
            $h = $h + $y;
            $y = 0;
        }
        //End issue 36

        $forceResize = $config['cropConfig']['forceResize'];

        $uploadUrl = $publicDir . '/' . urldecode($config['uploadConfig']['uploadDir']);

        $imageName = $params['imageName'];

        $src = $uploadUrl.'/'.$imageName;

        if (!is_dir($uploadUrl.'/'.$croppedImageDir.'/')) {
            mkdir($uploadUrl.'/'.$croppedImageDir.'/', 0755, true);
        }
        $ext = pathinfo($imageName, PATHINFO_EXTENSION);
        //set unique filename if defined inside the configuration
        if ($config['uploadConfig']['generateFilename']) {
            $imageName = sha1(uniqid(mt_rand(), true)).'.'.$ext;
        }
        $destSrc = $uploadUrl.'/'.$croppedImageDir.'/'.$imageName;

        $destW = $w;
        $destH = $h;

        if ($forceResize){
            $destW = $tarW;
            $destH = $tarH;
            if (round($w/$h, 2) != round($tarW/$tarH, 2)){
                // $destW = $w;
                // $destH = $h;
                list($destW, $destH) = $this->getMinResizeValues($w, $h, $tarW, $tarH);
            }
        }

        $this->resizeCropImage($destSrc,$src,0,0,$x,$y,$destW,$destH,$w,$h);

        $galleryThumbOk = false;
        $isGallery = isset($config['uploadConfig']['isGallery']) ? $config['uploadConfig']['isGallery'] : false;

        if ($isGallery)
        {
            if (!isset($config['cropConfig']['thumbs']) || !($thumbs = $config['cropConfig']['thumbs']) || !count($thumbs))
            {
                $config['cropConfig']['thumbs'] = array();
            }
            $config['cropConfig']['thumbs'][] = array('maxWidth' => $galleryThumbSize, 'maxHeight' => $galleryThumbSize, 'forGallery' => true);
        }

        //Create thumbs if asked
        $previewSrc = '/'.$config['uploadConfig']['webDir'] . '/' . $croppedImageDir . '/'. $imageName;
        if (isset($config['cropConfig']['thumbs']) && ($thumbs = $config['cropConfig']['thumbs']) && count($thumbs))
        {
            $thumbDir = $uploadUrl.'/'. $croppedImageDir . '/' . $thumbsDir .'/';
            if (!is_dir($thumbDir))
            {
                mkdir($thumbDir);
            }

            foreach ($thumbs as $thumb){
                $maxW = $thumb['maxWidth'];
                $maxH = $thumb['maxHeight'];

                if (!isset($thumb['forGallery']) && $maxW == $galleryThumbSize && $maxH == $galleryThumbSize){
                    $galleryThumbOk = true;
                }
                if (isset($thumb['forGallery']) && $galleryThumbOk) continue;

                list($w, $h) = $this->getMaxResizeValues($destW, $destH, $maxW, $maxH);

                $thumbName = $maxW.'x'.$maxH.'-'.$imageName;
                $thumbSrc = $thumbDir . $thumbName;
                $this->resizeCropImage($thumbSrc, $destSrc, 0, 0, 0, 0, $w, $h, $destW, $destH);
                if (isset($thumb['useAsFieldImage']) && $thumb['useAsFieldImage']){
                    $previewSrc = '/'.$config['uploadConfig']['webDir'] . '/' . $croppedImageDir . '/'. $thumbsDir . '/' . $thumbName;
                }
            }
        }

        return new Response(json_encode(array('success' => true,
                                              'filename'=>$croppedImageDir.'/'.$imageName,
                                              'previewSrc' => $previewSrc,
                                              'galleryThumb' =>  $croppedImageDir. '/' . $thumbsDir . '/'.$gThumbSize.'x'.$gThumbSize.'-' .$imageName)));
    }

    /**
     * Calculates and returns maximum size to fit in maxW and maxH for resize
     */
    private function getMaxResizeValues($srcW, $srcH, $maxW, $maxH){
        if($srcH/$srcW < $maxH/$maxW){
            $w = $maxW;
            $h = $srcH * ($maxW / $srcW);
        }
        else{
            $h = $maxH;
            $w = $srcW * ($maxH / $srcH);
        }
        return array($w, $h);
    }

    /**
     * Calculates and returns min size to fit in minW and minH for resize
     */
    private function getMinResizeValues($srcW, $srcH, $minW, $minH){
        if($srcH/$srcW > $minH/$minW){
            $w = $minW;
            $h = $srcH * ($minW / $srcW);
        }
        else{
            $h = $minH;
            $w = $srcW * ($minH / $srcH);
        }
        return array($w, $h);
    }

    /**
     * Calculates and returns maximum size to fit in maxW and maxH for crop
     */
    private function getMaxCropValues($srcW, $srcH, $maxW, $maxH)
    {
        $x = $y = 0;
        if($srcH/$srcW > $maxH/$maxW){
            $w = $srcW;
            $h = $srcH * ($maxW / $maxH);
            $y = round($srcH - $h / 2, 0);
        }
        else{
            $h = $srcH;
            $w = $srcW * ($maxH / $maxW);
            $x = round($srcW - $w / 2, 0);
        }
        return array($w, $h, $x, $y);
    }

    /**
     * Returns files from required directory
     *
     * @param Request $request
     */
    public function getLibraryImagesAction(Request $request, string $thumbsDir, string $publicDir)
    {
        $finder = new Finder();

        $finder->sortByType();
        $finder->depth('== 0');
        $result = array();
        $files = array();

        $result['thumbsDir'] = $thumbsDir;

        $libDir = $publicDir . '/' .  $request->request->get('dir');

        if (!is_dir($libDir)) {
            mkdir($libDir.'/', 0755, true);
        }

        foreach ($finder->in($libDir)->files() as $file) {
            $files[] = $file->getFilename();
        }
        $result['files'] = $files;

        return new Response(json_encode($result));
    }

    private function isGifAnimated($filename) {
        if(!($fh = @fopen($filename, 'rb')))
            return false;
        $count = 0;
        //an animated gif contains multiple "frames", with each frame having a
        //header made up of:
        // * a static 4-byte sequence (\x00\x21\xF9\x04)
        // * 4 variable bytes
        // * a static 2-byte sequence (\x00\x2C) (some variants may use \x00\x21 ?)

        // We read through the file til we reach the end of the file, or we've found
        // at least 2 frame headers
        while(!feof($fh) && $count < 2) {
            $chunk = fread($fh, 1024 * 100); //read 100kb at a time
            $count += preg_match_all('#\x00\x21\xF9\x04.{4}\x00(\x2C|\x21)#s', $chunk, $matches);
        }

        fclose($fh);
        return $count > 1;
    }

    /**
     * Crops or resizes image and writes it on disk
     */
    private function resizeCropImage($destSrc, $imgSrc, $destX, $destY, $srcX, $srcY, $destW, $destH, $srcW, $srcH)
    {
        $type = strtolower(pathinfo($imgSrc, PATHINFO_EXTENSION));

        switch ($type) {
            case 'jpg':
            case 'jpeg':
                $srcFunc = 'imagecreatefromjpeg';
                $writeFunc = 'imagejpeg';
                $imageQuality = 100;
                break;
            case 'gif':
                if ($this->isGifAnimated($imgSrc) && extension_loaded('imagick')) {
                    $image = new \Imagick($imgSrc);

                    $image = $image->coalesceImages();

                    foreach ($image as $frame) {
                        $frame->cropImage($srcW, $srcH, $srcX, $srcY);
                        $frame->thumbnailImage($destW, $destH);
                        $frame->setImagePage($destW, $destH, $destX, $destY);
                    }

                    $image = $image->deconstructImages();
                    $image->writeImages($destSrc, true);
                    return false;
                } else {
                    $srcFunc = 'imagecreatefromgif';
                    $writeFunc = 'imagegif';
                    $imageQuality = null;
                }
                break;
            case 'png':
                $srcFunc = 'imagecreatefrompng';
                $writeFunc = 'imagepng';
                $imageQuality = 9;
                break;
            default:
                return false;
        }

        $imgR = $srcFunc($imgSrc);

        if(round($srcW/$srcH, 2) != round($destW/$destH, 2)){
            $destW = $srcW;
            $destH = $srcH;
        }
        $dstR = imagecreatetruecolor( $destW, $destH );

        if($type == 'png'){
            imagealphablending( $dstR, false );
            imagesavealpha( $dstR, true );
        }

        imagecopyresampled($dstR,$imgR,$destX,$destY,$srcX,$srcY,$destW,$destH,$srcW,$srcH);

        switch ($type) {
            case 'gif':
            case 'png':
                imagecolortransparent($dstR, imagecolorallocate($dstR, 0, 0, 0));
            case 'png':
                imagealphablending($dstR, false);
                imagesavealpha($dstR, true);
                break;
        }

        $writeFunc($dstR,$destSrc,$imageQuality);
    }
}
