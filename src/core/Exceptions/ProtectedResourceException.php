<?php
namespace App\Kitchen\core\Exceptions;

use Exception;

class ProtectedResourceException extends Exception
{
    public function __construct($message = "Cet élément ne peut pas être supprimé", $code = 404, Exception $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}