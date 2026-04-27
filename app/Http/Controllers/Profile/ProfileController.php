<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
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
            'password' => 'required|string|min:8|confirmed',
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

        $image = $this->resizeSignatureImage($file->getRealPath(), 360, 120);

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

        $scale = min($maxWidth / $width, $maxHeight / $height, 1);
        $newWidth = max(1, (int) floor($width * $scale));
        $newHeight = max(1, (int) floor($height * $scale));
        $canvas = imagecreatetruecolor($newWidth, $newHeight);

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
        imagefilledrectangle($canvas, 0, 0, $newWidth, $newHeight, $transparent);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        $this->makeNearWhiteTransparent($canvas);

        ob_start();
        imagepng($canvas, null, 6);
        $contents = ob_get_clean();

        imagedestroy($source);
        imagedestroy($canvas);

        return ['contents' => $contents];
    }

    private function makeNearWhiteTransparent(\GdImage $image, int $threshold = 245): void
    {
        $width = imagesx($image);
        $height = imagesy($image);

        imagealphablending($image, false);
        imagesavealpha($image, true);

        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $rgba = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                if (
                    ($rgba['red'] ?? 0) >= $threshold &&
                    ($rgba['green'] ?? 0) >= $threshold &&
                    ($rgba['blue'] ?? 0) >= $threshold
                ) {
                    imagesetpixel($image, $x, $y, imagecolorallocatealpha($image, 255, 255, 255, 127));
                }
            }
        }
    }
}
