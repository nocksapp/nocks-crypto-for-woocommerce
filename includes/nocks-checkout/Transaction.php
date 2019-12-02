<?php

class Nocks_Transaction
{
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
        return $this->status === 'completed' || $this->status === 'paid';
    }

    function isOpen() {
        return $this->status === 'open';
    }

    function isCancelled() {
        return $this->status === 'cancelled';
    }

	function isExpired() {
    	return $this->status === 'expired';
	}
}