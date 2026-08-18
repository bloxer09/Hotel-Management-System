<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Room turnover buffer
    |--------------------------------------------------------------------------
    |
    | Minutes the previous guest must vacate before the next reserved
    | arrival. Latest safe checkout is:
    | next_reserved_check_in - turnover_buffer_minutes.
    |
    */

    'turnover_buffer_minutes' => (int) env('HOTEL_TURNOVER_BUFFER_MINUTES', 20),

    /*
    |--------------------------------------------------------------------------
    | Checkout warning window
    |--------------------------------------------------------------------------
    |
    | Minutes before expected_check_out (including Modified Checkout) to
    | surface upcoming checkout notifications for Admin, Front Desk, and
    | Housekeeping. Overdue alerts start after expected_check_out passes.
    |
    */

    'checkout_warning_minutes' => (int) env('HOTEL_CHECKOUT_WARNING_MINUTES', 60),

];
