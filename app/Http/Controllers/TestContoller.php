<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class TestContoller extends Controller
{
    public function index()
    {
        return response()->json(['message' => 'Test route is working!']);
    }   
}
