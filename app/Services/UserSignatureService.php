<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class UserSignatureService
{
    public function store(UploadedFile $file, int $userId): string
    {
        $relativePath = sprintf('signatures/users/user_%d.png', $userId);
        $image = $this->resizeSignatureImage($file->getRealPath(), 900, 260);

        Storage::disk('public')->put($relativePath, $image['contents']);

        return $relativePath;
    }

    private function resizeSignatureImage(string $sourcePath, int $maxWidth, int $maxHeight): array
    {
        [$width, $height, $type] = getimagesize($sourcePath) ?: [0, 0, 0];
        abort_if($width <= 0 || $height <= 0, 422, 'Invalid signature image');

        $source = match ($type) {
            IMAGETYPE_PNG => imagecreatefrompng($sourcePath),
            IMAGETYPE_JPEG => imagecreatefromjpeg($sourcePath),
            default => null,
        };

        abort_if(!$source, 422, 'Unsupported signature image');

        imagealphablending($source, false);
        imagesavealpha($source, true);

        $this->makeNearWhiteTransparent($source);
        $cropped = $this->cropTransparentSignature($source, 12);

        $cropWidth = imagesx($cropped);
        $cropHeight = imagesy($cropped);
        $scale = min($maxWidth / $cropWidth, $maxHeight / $cropHeight, 1);
        $newWidth = max(1, (int) floor($cropWidth * $scale));
        $newHeight = max(1, (int) floor($cropHeight * $scale));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
        imagefilledrectangle($canvas, 0, 0, $newWidth, $newHeight, $transparent);
        imagecopyresampled(
            $canvas,
            $cropped,
            0,
            0,
            0,
            0,
            $newWidth,
            $newHeight,
            $cropWidth,
            $cropHeight
        );

        ob_start();
        imagepng($canvas, null, 6);
        $contents = ob_get_clean();

        if ($cropped !== $source) {
            imagedestroy($cropped);
        }
        imagedestroy($source);
        imagedestroy($canvas);

        return ['contents' => $contents];
    }

    private function cropTransparentSignature(\GdImage $image, int $padding = 10): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $minX = $width;
        $minY = $height;
        $maxX = 0;
        $maxY = 0;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgba = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                $isVisible = ($rgba['alpha'] ?? 127) < 120;
                $isInk = min($rgba['red'] ?? 255, $rgba['green'] ?? 255, $rgba['blue'] ?? 255) < 235;

                if ($isVisible && $isInk) {
                    $minX = min($minX, $x);
                    $minY = min($minY, $y);
                    $maxX = max($maxX, $x);
                    $maxY = max($maxY, $y);
                }
            }
        }

        if ($minX > $maxX || $minY > $maxY) {
            return $image;
        }

        $minX = max(0, $minX - $padding);
        $minY = max(0, $minY - $padding);
        $maxX = min($width - 1, $maxX + $padding);
        $maxY = min($height - 1, $maxY + $padding);
        $cropWidth = $maxX - $minX + 1;
        $cropHeight = $maxY - $minY + 1;

        $cropped = imagecreatetruecolor($cropWidth, $cropHeight);
        imagealphablending($cropped, false);
        imagesavealpha($cropped, true);
        $transparent = imagecolorallocatealpha($cropped, 255, 255, 255, 127);
        imagefilledrectangle($cropped, 0, 0, $cropWidth, $cropHeight, $transparent);
        imagecopy($cropped, $image, 0, 0, $minX, $minY, $cropWidth, $cropHeight);

        return $cropped;
    }

    private function makeNearWhiteTransparent(\GdImage $image, int $whiteThreshold = 238, int $fadeStart = 205): void
    {
        $width = imagesx($image);
        $height = imagesy($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $colorIndex = imagecolorat($image, $x, $y);
                $rgba = imagecolorsforindex($image, $colorIndex);
                $r = $rgba['red'] ?? 0;
                $g = $rgba['green'] ?? 0;
                $b = $rgba['blue'] ?? 0;
                $oldAlpha = $rgba['alpha'] ?? 0;
                $minRgb = min($r, $g, $b);
                $saturation = max($r, $g, $b) - $minRgb;

                if ($minRgb < $fadeStart || $saturation > 28) {
                    continue;
                }

                if ($minRgb >= $whiteThreshold) {
                    $newAlpha = 127;
                } else {
                    $ratio = ($minRgb - $fadeStart) / max(1, $whiteThreshold - $fadeStart);
                    $newAlpha = max($oldAlpha, min(127, (int) round($ratio * 127)));
                }

                $newColor = imagecolorallocatealpha($image, $r, $g, $b, $newAlpha);
                imagesetpixel($image, $x, $y, $newColor);
            }
        }
    }
}
