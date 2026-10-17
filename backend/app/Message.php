<?php

namespace App;

class Message {

    public $id;
    public $english;
    public $arabic;

    function __construct($id,$english,$arabic) {
        $this->id = $id;
        $this->english = $english;
        $this->arabic = $arabic;
    }
}