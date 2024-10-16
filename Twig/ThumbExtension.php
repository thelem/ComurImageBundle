<?php
namespace Comur\ImageBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;

class ThumbExtension extends AbstractExtension implements GlobalsInterface
{
    protected $croppedDir;
    protected $thumbsDir;
    protected $galleryDir;
    protected $webDir;
    protected $transDomain;

    public function __construct($croppedDir, $thumbsDir, $webDir, $transDomain, $galleryDir)
    {
        $this->croppedDir = $croppedDir;
        $this->thumbsDir = $thumbsDir;
        $this->transDomain = $transDomain;
        $this->webDir = $webDir;
        $this->galleryDir = $galleryDir;
    }

    public function getFilters()
    {
        return array(
            new TwigFilter('thumb', array($this, 'getThumb')),
            new TwigFilter('gallery_thumb', array($this, 'getGalleryThumb')),
        );
    }

    /**
     * Returns thumb file if exists
     * @param string $origFilePath web path to original file (relative, ex: uploads/members/cropped/azda4qs.jpg)
     * @param integer $width desired thumb's width
     * @param integer $height desired thumb's height
     * @return string thumbnail path if thumbnail exists, if not returns original file path
     */
    public function getThumb($origFilePath, $width, $height)
    {
        $pathInfo = pathinfo($origFilePath);
        if(isset($pathInfo['dirname']) && isset($pathInfo['basename']))
        {
            $uploadDir = $pathInfo['dirname'] . '/';
            $filename = $pathInfo['basename'];

            $thumbSrc = $uploadDir . $this->thumbsDir . '/' . $width . 'x' . $height . '-' .$filename;

            // return $this->webDir.'/'.$thumbSrc;
            return $thumbSrc;

            // return file_exists($this->webDir.'/'.$thumbSrc) ? $thumbSrc : $uploadDir . $filename;
        }

        return $origFilePath;
    }

    public function getGalleryThumb($origFilePath, $width, $height)
    {
        return $this->getThumb($this->galleryDir.'/'.$origFilePath, $width, $height);
    }

    public function getName()
    {
        return 'comur_thumb_extension';
    }

    public function getGlobals(): array
    {
        return array('comur_translation_domain' => $this->transDomain);
    }
}
