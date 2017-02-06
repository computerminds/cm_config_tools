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

Details
------------------------

Configuration can be exported to code from active storage using the 
cm_config_tools module. This workflow allows precise control over what config to
export, although it does require you as the developer to find and decide exactly
what to export.

1. **Including config in a module**
   
   Add config items names to the `managed` subsection of the `cm_config_tools`
   section of your install profile's .info.yml file or a specific module's
   .info.yml file (creating it if necessary).
   See the [Choosing config to export](#choosing-config-to-export) section
   below, but once you have added some basic items (e.g. at least a node type),
   running the following may suffice to suggest what related config you might
   need:
   
   ```bash
   drush cm-config-tools-suggest MYMODULE
   ```
     
   This lists anything that is dependent on the config you have already listed
   (e.g. field instances for a node type).
   
   Copy the config items from the list that you want to export into the 
   `managed` section of your .info.yml file. Dependencies will then get added 
   where necessary when exporting.
   
   Note that you may want to run this command again, to check for config 
   dependent on your newly added config.
   
2. **Exporting config**
   
   Once all the configuration you want exporting is added to the .info.yml file,
   enable the module and then run:
   
   ```bash
   drush cm-config-tools-export
   ```
   
3. **Reverting config**
    
   To replace configuration with what is in code, run the following:
   
   ```bash
   drush cm-config-tools-import
   ```
   
   Or from PHP (e.g. in an update hook or /devel/php):
   
   ```php
   // Import configuration from all projects containing a 'cm_config_tools' key.
   \Drupal::service('cm_config_tools')->importAll();
   ```
   
4. **Deleting config**
    
   Config can be deleted as part of the `drush cm-config-tools-import` command.
   Specify items to delete under a `delete` section within the `cm_config_tools`
   section in a module's info.yml file. Then run that command.
   
   Alternatively, deleting individual configuration can be done separately, for
   example:
   
   ```bash
   drush config-delete 'field.field.node.article.body'
   ```
   
   Or from PHP (e.g. in an update hook or /devel/php):
   
   ```php
   \Drupal::service('config.factory')->getEditable($config_name)->delete();
   ```

Replace 'MYMODULE' in all of these with your module name, or the name of the
install profile.

This module is probably not compatible with other configuration-managing 
projects. It is loosely based on old workflows used with Features. With
Features, creating a module is often done through the admin UI, which allows
easy selection of what to export to a module. Unfortunately, this step is just a
bit more manual with cm_config_tools as you have to create your module's
.info.yml yourself (but with the help available). The equivalents of `drush fu`
and `drush fr` are `drush cmce` and `drush cmci`, respectively.

This approach provides more control than synchronizing a complete directory of
config, allowing only specific config to be controlled.

### Choosing config to export ###

It is the developer's responsibility to be aware of what configuration should be
exported to a module, but here is some help! Start by adding items you know you
want, such as `node.type.article`. Then run the following drush command, which
will list anything that is dependent on the listed config:

```bash
drush cm-config-tools-suggest MYMODULE
```

Other related configuration might also be found by running:

```bash
drush config-list | grep 'article'
```

Note that there could still be other config items that may reference articles
without being named after them, so you could try querying the `config` table
with SQL like this:

```sql
SELECT * FROM `config` WHERE `data` LIKE '%article%';
```

### Drush command aliases ###

`drush cmce` is the alias for `drush cm-config-tools-export`.  
`drush cmci` is the alias for `drush cm-config-tools-import`.
`drush cmcs` is the alias for `drush cm-config-tools-suggest`.  
`drush cdel` is the alias for `drush config-delete`.  
