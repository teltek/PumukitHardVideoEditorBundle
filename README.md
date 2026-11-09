# HardVideoEditorBundle

Bundle for the [PuMuKIT Video Platform 6.0](https://github.com/pumukit/PuMuKIT/tree/6.0.x) or higher. It adds a
mono-stream video cutter to the back-office: each cut creates a new multimedia object in the same series, encoded
from the master of the original.

## Installation

```bash
composer require teltek/pumukit-hard-video-editor-bundle
```

If Symfony Flex does not do it, add the bundle to `config/bundles.php`:

```php
Pumukit\HardVideoEditorBundle\PumukitHardVideoEditorBundle::class => ['all' => true],
```

## Configuration

```yaml
pumukit_hard_video_editor:
    enable: true
    default_set_role: Participant
```

| Option | Default | Description |
|--------|---------|-------------|
| `enable` | `true` | If false, the editor pages return 404 and nothing else of the bundle is loaded: no menu option nor encoder profiles |
| `default_set_role` | `Participant` | Code of the role given to the person chosen in the editor |

## Usage

The editor is opened from **Cut multimedia object** in the menu of a multimedia object. It needs the
`ROLE_ACCESS_MULTIMEDIA_SERIES` permission and permission to edit that video: a user with personal scope can only cut
the videos they own. Audio-only, multistream and Opencast videos cannot be cut.

Choose the start and the end on the timeline, with the handles, the time fields or the **I** and **O** keys, and fill
in the metadata of the new video. **Cut** opens the new video; **Cut and continue** stays in the editor to make
another cut.

Like any new video, it gets the status and the default publication channels of the user's permission profile, and it
inherits from the original:

- the recording date, subtitle, line 2, keywords, copyright and license;
- the tags, except the publication channels and decisions;
- the people with their roles, the groups and the access (public, password, groups...).

The title and description are those of the form, filled in with the original ones. The comments of the form are
followed by the origin of the cut. The thumbnail can be the current frame or an uploaded JPEG, PNG or WebP image.

### Tracks

| Master | Broadcastable | Encoding profiles | Result |
|:------:|:-------------:|-------------------|--------|
| | ✓ (default) | `video_master_broadcastable_trimming` | One track that is the master and can be played |
| ✓ | | `video_master_trimming` | A high quality master; the broadcastable track is generated when the video is published |
| ✓ | ✓ | `video_master_trimming` and `video_broadcastable_trimming` | Both tracks, as in a normal upload |

The profiles are added to the encoder by the bundle, only when it is enabled, unless the application already defines a
profile with the same name, so they can be overridden in `config/packages/encoder.yaml`.

## Upgrade from 4.x

- The bundle requires PHP 8.5 and PuMuKIT 6.0.
- The editor is a new Stimulus controller with Bootstrap 5: Prototype, Popcorn, jQuery UI and the Bootstrap 2
  plugins are gone. Remove the old copies with `rm -rf public/bundles/pumukithardvideoeditor`.
- The editor checks the permissions and requires a CSRF token. Personal scope users can no longer cut the videos of
  others.
- The cut keeps the milliseconds and the profiles seek before decoding, so cuts are exact and faster.
- The **Generate broadcastable master** checkbox is replaced by the **Master** and **Broadcastable** checkboxes.
  The new `video_master_broadcastable_trimming` profile is used when only **Broadcastable** is chosen, so every cut
  has a master and can be cut again.
- The new video inherits the metadata described above instead of only the series and the recording date.
