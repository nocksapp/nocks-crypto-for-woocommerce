<?php

class Nocks_Payment
{

    protected $paid = false;

    function __construct($data) {
        if (isset($data['success'])) {
            if ($data['success']['status'] == 'success') {
                $this->paid = true;
            }
            else {
                $this->paid = false;
            }
        }

        $this->paid = false;
    }

    function isPaid() {
        return $this->paid;
    }


}