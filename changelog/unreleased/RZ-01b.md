# RZ-01b: uncaught exceptions always send a valid error status

## Fixed
- `ErrorRenderer::showException()` sent the exception code verbatim as the HTTP status, so a plain
  exception (code 0) produced the invalid status line `HTTP/1.1 0` and `php -S` dropped the response.
  The status now comes from the new `ErrorRenderer::statusFor()`: the exception code when it is an
  integer in 400-599, otherwise 500.

## Changed
- Non-numeric codes (PDO SQLSTATE strings) now map to 500 instead of 400, and out-of-range numeric
  codes (200, 302, 600, negatives) map to 500 instead of being sent as-is. `Razy\Error` keeps its
  400 default.
- `Razy\Error::__construct()` and `Statement\Builder::init()` declare their nullable parameters
  explicitly (`?Throwable`, `?Statement`). PHP 8.4+ printed an implicit-nullable deprecation when the
  class loaded, and that output broke every later `header()` call in the same process.
