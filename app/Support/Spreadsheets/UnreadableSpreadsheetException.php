<?php

namespace App\Support\Spreadsheets;

use RuntimeException;

/**
 * The uploaded file is not a CSV or XLSX workbook that can be read.
 */
class UnreadableSpreadsheetException extends RuntimeException {}
