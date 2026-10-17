<?php

if (!function_exists('Success')) {
    function Success($message,$status=200)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'code' => $status,
            'data' => null,

        ], $status);
    }
}

if (!function_exists('SuccessData')) {
    function SuccessData($message, $data,$status= 200)
    {

        return response()->json([
            'success' => true,
            'message' => $message,
            'code' => $status,
            'data' => $data,
        ], $status);
    }
}
if (!function_exists('Failed')) {
    function Failed($message ='An unexpected error occurred. Please try again later.',$status =500)
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'code' => $status,
            'data' => null,

        ], $status);
    }
}

if (!function_exists('Pagination')) {
    function Pagination($data)
    {
        $data = $data->toArray();

        return response()->json([
            'success' => true,
            'message' => 'Found Successfully',
            'per_page' => $data['per_page'],
            'total' => $data['total'],
            'current_page' => $data['current_page'],
            'last_page' => $data['last_page'],
            'data' => $data['data'],
        ], 200);
    }
}


if (!function_exists('uploadImage')) {
    function uploadImage($image, $path)
    {
        $name = $image->getClientOriginalName();
        $newName = rand(9999999999, 99999999999) . $name;
        $image->move(public_path($path), $newName);
        return  $newName;
    }
}


if (!function_exists('returnPerPage')) {
    function returnPerPage()
    {
        $perPage = 10;
        if (request()->hasHeader('X-Per-Page') && is_numeric(request()->header('X-Per-Page')) && request()->header('X-Per-Page') > 0) {
            $perPage = request()->header('X-Per-Page');
        } elseif (request()->hasHeader('perPage') && is_numeric(request()->header('perPage')) && request()->header('perPage') > 0) {
            $perPage = request()->header('perPage');
        } elseif (request()->has('per_page') && is_numeric(request()->input('per_page')) && request()->input('per_page') > 0) {
            $perPage = request()->input('per_page');
        }
        return (int) $perPage;
    }
}

if (!function_exists('CheckVideoOrImage')) {
    function CheckVideoOrImage($image)
    {
        if (!$image) {
            return null;
        }
        $image_extensions = ['.jpg', '.png', 'jpeg', '.gif', 'tiff', '.bmp', '.svg', 'webp', 'heic'];
        $extension = substr($image, -4);
        if (!in_array($extension, $image_extensions)) {
            return true;
        }
        return false;
    }
}
