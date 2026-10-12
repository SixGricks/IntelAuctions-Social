<?php
/**
 * Reading OneUp's answers, whatever their exact shape (oneup.php): the rows
 * of a listing, the post id in a scheduling answer, and an error reported
 * inside a 200 answer. Shapes from the real account where known.
 */
require_once IAS_PATH . 'includes/settings.php';
require_once IAS_PATH . 'includes/oneup.php';

// Listings: the real account answers {"accounts": […]} and {"categories": […]}; lists {"posts": […], "next_start": …}.
$accts = ['accounts' => [
  ['category_id' => 170109, 'social_network_name' => 'Intel Auctions (5940 Plank Road  Narvon  PA  US )', 'social_network_id' => 'accounts/1/locations/2', 'social_network_type' => 'GBP'],
  ['category_id' => 170109, 'social_network_name' => 'Intel Auctions (Narvon)', 'social_network_id' => '1336677789520495', 'social_network_type' => 'Facebook'],
]];
$pairs = ias_oneup_pairs($accts, ['social_network_id', 'id'], ['social_network_name', 'username']);
t_eq(['1336677789520495', 'Intel Auctions (Narvon)'], [$pairs[1][0] ?? '', $pairs[1][1] ?? ''], 'accounts under "accounts" are read (the Facebook page and its name)');
t_eq(3, count(ias_oneup_pairs(['categories' => [['id' => 1, 'category_name' => 'A'], ['id' => 2, 'category_name' => 'B'], ['id' => 3, 'category_name' => 'C']]], ['id'], ['category_name'])), 'categories under "categories" too');
t_eq(2, count(ias_oneup_rows(['data' => [['id' => 1], ['id' => 2]]])), '…or under "data"');
t_eq(2, count(ias_oneup_rows([['id' => 1], ['id' => 2]])), '…or a bare list');
t_eq([], ias_oneup_rows(['posts' => [], 'start' => 0, 'count' => 0, 'next_start' => null]), 'an empty list is empty, not its counters');

// The post id in a scheduling answer.
t_eq('53690404', ias_oneup_find_id(['error' => false, 'data' => ['post_id' => 53690404]]), 'post_id inside data');
t_eq('77', ias_oneup_find_id(['message' => 'ok', 'data' => [['post_id' => '77']]]), 'post_id in a list');
t_eq('12', ias_oneup_find_id(['id' => 12]), 'a bare id');
t_eq('', ias_oneup_find_id(['message' => 'Post scheduled successfully']), 'no id: none (the read-back finds it by its text and time)');

// Errors inside a 200 answer.
t_eq('Invalid API key', ias_oneup_error_in(['error' => true, 'message' => 'Invalid API key']), 'error: true with a message');
t_eq('Facebook token expired', ias_oneup_error_in(['status' => 'error', 'msg' => 'Facebook token expired']), 'status: error');
t_eq('Not allowed', ias_oneup_error_in(['success' => false, 'message' => 'Not allowed']), 'success: false');
t_eq('', ias_oneup_error_in(['error' => false, 'message' => 'Post scheduled successfully', 'data' => ['post_id' => 1]]), 'error: false is not an error');
t_eq('', ias_oneup_error_in(['posts' => [], 'next_start' => null]), 'a list is not an error');
