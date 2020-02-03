# WP dependency visualizer

Shows the following inter-plugin and inter-theme dependencies:

- WP actions
- WP filters
- PHP functions
- PHP classes

## Installation

Install just like any other WordPress plugin.

Alternatively, install via Composer:

```
{
  "name": "your/wp-site",
  "type": "project",
  "repositories": [
    {
      "type": "git",
      "url": "https://github.com/Crusse/wp-dependency-visualizer.git"
    }
  ],
  "require": {
    "crusse/wp-dependency-visualizer": "dev-master"
  }
}
```

## Usage

Go to `/wp-admin/admin.php?page=dependency-visualizer` and click the button.

## Note

The plugin uses static analysis (PHP file parsing) to analyze the dependencies,
so it's not able to currently find _all_ dependencies. For example, if a plugin
defines a hook like `do_action('my-plugin-' . $hookName)`, then static analysis
cannot catch the dependency.

