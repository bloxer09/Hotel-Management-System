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

];
