<?php

    $this->reports = [];
    $report_failed_inserts = !empty($options['failed_insert']) ? $options['failed_insert'] : '';
    $report_failed_updates = !empty($options['failed_update']) ? $options['failed_update'] : '';
    $report_missing_imgs = count($this->stf_get_tar_posts('_thumbnail_id', '?', 'NOT EXISTS')) > 0 ? $this->stf_get_tar_posts('_thumbnail_id', '?', 'NOT EXISTS') : [];

    if( '' != $report_failed_inserts ) {
        $this->reports['Recent Import: New SF Profiles (Acct IDs) That Could Not be Created in WordPress'] = $report_failed_inserts;
    }

    if( '' != $report_failed_updates ) {
        $this->reports['Recent Import: Existing Profiles (Post Titles) That Could Not be Updated in WordPress'] = $report_failed_updates;
    }

    if( !empty($report_missing_imgs) ) {
        $this->reports['Profiles With No Featured Images'] = $report_missing_imgs;
    }

    // Report Empty Custom Fields
    $empty_fields = [
        'Page Subhead' => 'page_subhead',
        'Account ID' => 'account_id_new',
        'Reviews' => 'reviews',
        'Artist Educational Program' => 'artist_ed_program_check',
        'Artist Performance Fee' => 'artist_performance_fee_new',
        'Artist Fee Negotiable' => 'artist_fee_negotiable',
        'Non Profit Discount' => 'non_profit_discount_new',
        'Artist Phone' => 'artist_phone',
        'Artist Email' => 'artist_email',
        'Artist Website' => 'artist_website',
        'Artist Vimeo ID' => 'artist_vimeo',
        'Artist YouTube ID' => 'artist_youtube_id_new',
        'Artist SoundCloud ID' => 'artist_soundcloud_id_new',
    ];

    foreach( $empty_fields as $label => $name ) {
        $list = count($this->stf_get_tar_posts($name, '', '=')) > 0 ? $this->stf_get_tar_posts($name, '', '=') : [];

        if( !empty($list) ) {
            $this->reports['Profiles With Empty "' . $label . '" field'] = $list;
        }
    }
