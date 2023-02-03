<?php

namespace App\Helpers;

class AppHelpers
{
    public static function sendResponse($data = false, $msg = null, $status_code = 401)
    {
        return response()->json(['result' => $data, 'message' => $msg], $status_code);
    }

    public static function uploadImage($image, $path)
    {
        $image_name = time() . '.' . $image->getClientOriginalExtension();
        $image->move(public_path($path), $image_name);
        return $image_name;
    }
}
