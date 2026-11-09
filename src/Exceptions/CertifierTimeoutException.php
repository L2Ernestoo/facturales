<?php

namespace Lc\Fel\Exceptions;

use Exception;

/**
 * El certificador no respondió a tiempo. El DTE pudo haberse certificado
 * del lado del certificador aunque aquí no tengamos respuesta.
 */
class CertifierTimeoutException extends Exception
{
}
