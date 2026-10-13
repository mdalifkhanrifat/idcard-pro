<?php

return [

    /*
     * Rows per page a list offers to choose from (the legacy lists offered 10 to 2000). The pages read them
     * from GET /auth/me; any API client may ask for any number up to max_per_page.
     */
    'page_sizes' => [10, 15, 25, 50, 100, 200, 500, 1000, 2000],

    /*
     * Most rows one page of any list may hold.
     */
    'max_per_page' => (int) env('LIST_MAX_PER_PAGE', 2000),

];
