<?php

return [

    /*
    |--------------------------------------------------------------------------
    | TransFlag
    |--------------------------------------------------------------------------
    |
    | Ten-digit mask returned in the handshake that tells the terminal which
    | record types to push to the server. Position by position:
    |
    |   1 AttLog     2 OpLog      3 AttPhoto   4 EnrollUser  5 ChgUser
    |   6 EnrollFP   7 ChgFP      8 FPImage    9 Face       10 UserPic
    |
    | Positions 6 and 7 are what make a terminal upload a fingerprint template
    | as soon as it is enrolled or changed — without them, the only way to get
    | templates is to ask for them explicitly. The historic value here was
    | 1111000000 (attendance only), which is why no templates ever arrived.
    |
    | The default below turns on 1-7 and leaves 8-10 off: fingerprint images,
    | face templates and user photos are large and would flood the server for
    | no benefit here. Set ADMS_TRANS_FLAG if a particular fleet needs them.
    |
    */
    'trans_flag' => env('ADMS_TRANS_FLAG', '1111111000'),

    /*
    |--------------------------------------------------------------------------
    | Fingerprint pull
    |--------------------------------------------------------------------------
    |
    | finger_ids  Finger slots queried when pulling templates for an employee.
    |             A terminal stores up to ten (0-9); querying all ten costs ten
    |             commands per employee, so narrow this if your enrolment
    |             policy only uses a couple of fingers.
    |
    | query_userinfo  Also queue a DATA QUERY USERINFO alongside a bulk pull,
    |             so the user record arrives with the templates.
    |
    */
    'finger_ids' => array_map(
        'intval',
        explode(',', (string) env('ADMS_FINGER_IDS', '0,1,2,3,4,5,6,7,8,9'))
    ),

    'query_userinfo' => (bool) env('ADMS_QUERY_USERINFO', true),

    /*
    | Guard against queueing thousands of commands in a single click.
    */
    'max_pull_commands' => (int) env('ADMS_MAX_PULL_COMMANDS', 2000),

    /*
    | How many queued commands to hand a terminal in one /iclock/getrequest
    | response. Terminals poll every few seconds, so a small batch drains a
    | large pull quickly without sending a multi-megabyte reply. 0 = no limit.
    */
    'commands_per_request' => (int) env('ADMS_COMMANDS_PER_REQUEST', 20),

    /*
    | How long to wait before queueing another "set the clock" command for the
    | same terminal, in minutes. The correction is marked executed as soon as it
    | is handed to the terminal, so without a cooldown a terminal that stays out
    | of sync gets a fresh device_commands row on every poll.
    */
    'clock_correction_cooldown' => (int) env('ADMS_CLOCK_CORRECTION_COOLDOWN', 30),

    /*
    |--------------------------------------------------------------------------
    | Webhook delivery
    |--------------------------------------------------------------------------
    |
    | Seconds to wait for a device's webhook receiver to answer. The POST runs
    | after the terminal has already been answered, so a slow receiver no
    | longer delays the terminal - but it does hold a PHP worker for this long,
    | which is the reason to keep it short.
    |
    | Delivery is logged to the "webhook" channel in config/logging.php.
    |
    */
    'webhook_timeout' => (int) env('ADMS_WEBHOOK_TIMEOUT', 5),

];
