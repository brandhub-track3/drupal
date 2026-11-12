<?php

/**
 * @file
 * Returns a safe HTML body larger than 500 KiB for claim-check risk tests.
 */

return str_repeat('x', 512 * 1024 + 1);
