<?php

namespace Tey\Mod\Survey;

use RuntimeException;

/** @internal A survey request for a folder the survey may not read. */
final class OutOfScope extends RuntimeException {}
