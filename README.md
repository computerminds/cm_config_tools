# ComputerMinds Config tools
Provides advanced configuration management workflow functionality.

```bash
drush help cm-config-tools-import
```

Example usage from PHP (e.g. for an update hook):

```php
// Import configuration from all projects that contain a 'cm_config_tools' key.
\Drupal::service('cm_config_tools')->import();

// Export configuration.
\Drupal::service('cm_config_tools')->export();
```
