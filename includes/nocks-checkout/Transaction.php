<?php

class Nocks_Transaction
{

    protected $paid = false;
    public $metadata;
    public $id;
    public $status;


    function __construct($data) {
        if (isset($data['data'])) {
            $data = $data['data'];

            $this->id = $data['uuid'];
            $this->status = $data['status'];
        }
    }

    function isPaid() {
        return $this->status === 'success';
    }

    function isOpen() {
        return $this->status === 'open';
    }

    function isCancelled() {
        return $this->status === 'cancelled';
    }


}