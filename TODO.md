# TODO

- Check if class definitions and usages are collected properly. Also treat PHP
  interface definitions/usages as "class" definitions/usages.
- Static analysis doesn't catch all definitions and usages. Dynamically collect
  data to the db of add_action()/add_filter()/do_action()/apply_filters() calls.
- Create a wp-cli command to prevent HTTP timeout issues
- Ignore all built-in PHP class and function names. Some WP plugins define
  functions such as `stripos()`.
