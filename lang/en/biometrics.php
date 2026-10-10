<?php

return [
    'settings' => [
        'nothing_on' => 'Turn on at least one kind of capture, or turn biometrics off.',
        'positions_needed' => 'Choose at least one position to capture.',
        'drivers_needed' => 'Choose at least one way to capture (scanner, camera or upload).',
        'count_too_high' => 'Ask for no more than the chosen positions, or allow other positions to stand in.',
    ],
    'capture' => [
        'disabled' => 'Biometrics are turned off for this organization.',
        'not_for_family' => 'This organization does not capture biometrics of family members.',
        'modality_off' => 'This organization does not capture this kind of biometric.',
        'position_not_allowed' => 'This position is not one the organization captures.',
        'driver_not_allowed' => 'This capture method is not allowed for this kind of biometric.',
        'format_not_allowed' => 'This format is not accepted for this kind of biometric.',
        'image_invalid' => 'The image is not a valid :format file.',
        'image_too_large' => 'The image may be at most :kb KB.',
        'template_not_allowed' => 'This kind of biometric has no template.',
        'template_invalid' => 'The template could not be read.',
        'template_too_large' => 'The template may be at most :kb KB.',
        'low_quality_retry' => 'Quality :quality is below the required :min. Please try again (tries left: :left).',
        'low_quality_confirm' => 'Quality :quality is still below the required :min (tries: :tries). Keep the best capture anyway?',
        'note_required' => 'Please add a note saying why it cannot be captured.',
    ],
];
