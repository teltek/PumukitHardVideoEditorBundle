PumukitVideoEditorBundle
========================

This bundle provides a basic mono-stream video editor. Useful to create new multimedia objects from hard trimming operations.

```bash
composer require teltek/pumukit-hard-video-editor-bundle
```

if not, add this to config/bundles.php

```
Pumukit\HardVideoEditorBundle\PumukitHardVideoEditorBundle::class => ['all' => true]
```

Then execute the following commands

```bash
php bin/console cache:clear
php bin/console cache:clear --env=prod
php bin/console assets:install
```
