<?php

namespace Tey\Mod\Templates;

use RuntimeException;

/** A broken generator template is a warning, never a broken application. */
final class InvalidTemplate extends RuntimeException {}
