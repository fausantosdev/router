<?php

declare(strict_types=1);

namespace CoffeeCode\Router;

/** Invalid developer configuration; never masquerades as HTTP 404/405. */
class ConfigurationException extends \LogicException
{
}
