<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Models\Users\User as WorkflowUser;
use App\Support\SqlServerDb;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ProfileController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Show the form for editing the user's profile.
     */
    public function edit()
    {
        $user = Auth::user();
        return view('profile.edit', compact('user'));
    }

    /**
     * Update the user's profile information.
     */
    public function update(Request $request)
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:sqlsrv_menam.users,email,' . $user->id,
            'phone' => 'required|string|max:20',
            'department' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:255',
            'signature' => 'nullable|image|mimes:png,jpg,jpeg|max:2048',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $payload = [
            'name' => $request->name,
            'username' => WorkflowUser::uniqueUsernameForName($request->name, (int) $user->id),
            'email' => $request->email,
            'phone' => $request->phone,
            'department' => $request->department,
            'position' => $request->position,
        ];

        if ($request->hasFile('signature') && $request->file('signature')->isValid()) {
            if (!blank($user->signature_path)) {
                Storage::disk('public')->delete($user->signature_path);
            }

            $payload['signature_path'] = $this->storeSignature($request->file('signature'), (int) $user->id);
            $payload['signature_uploaded_at'] = now();
        }

        $user->update($payload);

        return redirect()->back()->with('success', 'อัพเดทข้อมูลส่วนตัวเรียบร้อยแล้ว!');
    }

    /**
     * Show the form for changing password.
     */
    public function changePassword()
    {
        return view('profile.change-password');
    }

    /**
     * Update the user's password.
     */
    public function updatePassword(Request $request)
    {
        $user = Auth::user();

        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'password' => 'required|string|min:4|confirmed',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator);
        }

        // Check current password
        if (!Hash::check($request->current_password, $user->password)) {
            return redirect()->back()->withErrors(['current_password' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง']);
        }

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        return redirect()->back()->with('success', 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว!');
    }

    private function storeSignature($file, int $userId): string
    {
        $dir = 'signatures/users';
        $fileName = sprintf('user_%d.png', $userId);
        $relativePath = "{$dir}/{$fileName}";

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

        // 1) ลบพื้นหลังขาว/เทาอ่อนให้โปร่งใส
        $this->makeNearWhiteTransparent($source);

        // 2) crop พื้นที่ว่างรอบลายเซ็นออก
        $cropped = $this->cropTransparentSignature($source, 12);

        $cropWidth = imagesx($cropped);
        $cropHeight = imagesy($cropped);

        // 3) resize หลัง crop แล้ว ลายเซ็นจะใหญ่ขึ้นจริง
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

        imagedestroy($source);
        imagedestroy($cropped);
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

                $r = $rgba['red'] ?? 255;
                $g = $rgba['green'] ?? 255;
                $b = $rgba['blue'] ?? 255;
                $alpha = $rgba['alpha'] ?? 127;

                // alpha 127 = โปร่งใส, 0 = ทึบ
                $isVisible = $alpha < 120;

                // กันกรณีพื้นหลังเทา/ขาวที่ยังหลงเหลือ
                $isInk = min($r, $g, $b) < 235;

                if ($isVisible && $isInk) {
                    $minX = min($minX, $x);
                    $minY = min($minY, $y);
                    $maxX = max($maxX, $x);
                    $maxY = max($maxY, $y);
                }
            }
        }

        // ถ้าหาเส้นลายเซ็นไม่เจอ ให้คืนรูปเดิม
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

        imagecopy(
            $cropped,
            $image,
            0,
            0,
            $minX,
            $minY,
            $cropWidth,
            $cropHeight
        );

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
                $maxRgb = max($r, $g, $b);
                $saturation = $maxRgb - $minRgb;

                // พื้นหลังขาว/เทาอ่อน มักจะมีค่าสีใกล้กัน และสว่างมาก
                $isLightBackground = $minRgb >= $fadeStart && $saturation <= 28;

                if (!$isLightBackground) {
                    continue;
                }

                // ขาวมาก = โปร่งใส 100%
                if ($minRgb >= $whiteThreshold) {
                    $newAlpha = 127;
                } else {
                    // ช่วงขอบ ๆ สีเทาอ่อน ให้ค่อย ๆ โปร่งใส ลดขอบขาวรอบลายเซ็น
                    $ratio = ($minRgb - $fadeStart) / max(1, ($whiteThreshold - $fadeStart));
                    $newAlpha = (int) round($ratio * 127);
                    $newAlpha = max($oldAlpha, min(127, $newAlpha));
                }

                $newColor = imagecolorallocatealpha($image, $r, $g, $b, $newAlpha);
                imagesetpixel($image, $x, $y, $newColor);
            }
        }
    }
}
