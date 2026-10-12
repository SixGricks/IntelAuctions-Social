<?php
/**
 * Intel Auctions Social — Facebook Event cards.
 *
 * Each open house and each auction day should be a Facebook Event, so anyone
 * who taps Interested gets Facebook's own reminder. Facebook does not let
 * outside apps create a Page's events through its API, and OneUp offers events
 * only for Google Business Profile — so the plugin prepares each event instead:
 * title, start and end, the address, a description and a cover picture, as a
 * "Create event" card on the Social Calendar and in the Monday email. Making it
 * in Meta Business Suite is a minute of copy and paste; **Mark created** (or
 * **Skip**) takes the card away.
 *
 * A card appears once the listing's Listing post has gone out (or its time has
 * come — while nothing is live, the planned time stands in), or, for a listing
 * whose Listing post was skipped, once its auction week starts; and only while
 * the event is still ahead. The text is built from the listing's facts, no AI.
 */

if (!defined('ABSPATH')) exit;

/** Cards marked created or skipped, on the listing: key => ['status', 'at', 'by']. */
if (!function_exists('ias_event_marks')) {
  function ias_event_marks(int $listing_id): array {
    $m = get_post_meta($listing_id, '_ias_fb_events', true);
    return is_array($m) ? $m : [];
  }
}

/** Has this listing's first post gone out (so its events should exist)? */
if (!function_exists('ias_events_due_for')) {
  function ias_events_due_for(array $f, DateTimeImmutable $now): bool {
    $lp = ias_post_by_slot('listing:' . (int) $f['id']);
    $nowsql = $now->format('Y-m-d H:i:s');
    if ($lp) {
      if (in_array($lp['status'], ['published', 'pushed'], true) && !empty($lp['scheduled_at']) && $lp['scheduled_at'] <= $nowsql) return true;
      if ($lp['status'] === 'published') return true;
      if (in_array($lp['status'], ['planned', 'approved'], true) && !empty($lp['scheduled_at']) && $lp['scheduled_at'] <= $nowsql) return true;
      if ($lp['status'] !== 'skipped') return false;
    }
    // No Listing post (skipped): from the start of auction week.
    return $f['sale_day'] !== '' && $now->format('Y-m-d') >= ias_day_add((string) $f['sale_day'], -10);
  }
}

/** The event's cover: the listing's best wide photo (landscape crop), else its brochure, else a branded card. */
if (!function_exists('ias_event_cover')) {
  function ias_event_cover(array $f): string {
    $grades = ias_photo_grades((array) $f['photos']);
    $pick = $f['featured'] > 0 ? (int) $f['featured'] : (int) (ias_pick_photos($grades, 'auction_week', [])[0] ?? 0);
    if ($pick > 0) {
      $crops = ias_photo_crops($pick);
      return (string) ($crops['landscape'] ?? ias_attachment_url($pick, 'large'));
    }
    if ((int) $f['brochure'] > 0) return ias_attachment_url((int) $f['brochure'], 'large');
    $card = ias_graphic_card($f, 'ias-event-card-' . (int) $f['id'], 1200, 628);
    return $card ? (string) $card['url'] : '';
  }
}

/** The cards for one listing, due or not: [key => card]. */
if (!function_exists('ias_event_cards_for')) {
  function ias_event_cards_for(array $f): array {
    $cards = [];
    $place = trim(($f['street'] !== '' ? $f['street'] : $f['title']) . ($f['town'] !== '' ? ', ' . $f['town'] : ''));
    $link = ias_tracked_link((string) $f['url'], 'event', 0);
    $who = !empty($f['lead']['name']) ? $f['lead']['name'] . ' at ' . ($f['lead']['phone'] ?: $f['office_phone']) : 'the office at ' . $f['office_phone'];
    $bid = $f['bidding'] === 'phone' ? 'This is an Intel Phone Bidding auction.' : ($f['bidding'] === 'in_person' ? 'This is an in-person auction.' : '');
    $sale = $f['date'] instanceof DateTimeImmutable ? $f['date']->format('l, F j \a\t g:i a') : 'date to be announced';

    foreach ($f['open_houses'] as $oh) {
      $key = 'oh' . (int) $oh['n'] . '-' . $oh['start']->format('Ymd');
      $cards[$key] = [
        'key' => $key, 'kind' => 'Open house', 'listing' => (int) $f['id'],
        'title' => 'Open House: ' . $place,
        'start' => $oh['start'], 'end' => $oh['end'],
        'where' => $f['address'],
        'description' => "Walk the property before it sells at auction on {$sale}. Open house " . ia_open_house_format($oh, 'range', 'l, F j,') . ' (' . ia_open_house_hours_label((float) $oh['hours']) . ').'
          . ($bid !== '' ? ' ' . $bid : '') . "\n\nQuestions? Call {$who}.\n\nPhotos, terms and the full listing: {$link}",
      ];
    }
    if ($f['date'] instanceof DateTimeImmutable) {
      $key = 'sale-' . $f['date']->format('Ymd');
      $cards[$key] = [
        'key' => $key, 'kind' => 'Auction', 'listing' => (int) $f['id'],
        'title' => ($f['kind'] === 'liquidation' ? 'Auction: ' : 'Public Auction: ') . $place,
        'start' => $f['date'], 'end' => $f['date']->modify('+2 hours'),
        'where' => $f['address'],
        'description' => ($f['kind'] === 'liquidation' ? 'Liquidation auction' : 'Public auction') . " on {$sale}." . ($bid !== '' ? ' ' . $bid : '')
          . ($f['starting_bid_raw'] !== '' && $f['kind'] !== 'liquidation' ? ' Starting bid ' . $f['starting_bid_raw'] . '.' : '')
          . "\n\nQuestions? Call {$who}.\n\nPhotos, terms and the full listing: {$link}",
      ];
    }
    return $cards;
  }
}

/**
 * The cards to make now: due, still ahead, not marked. Optional $from/$to
 * (Y-m-d) keep only events in that range (the Monday email's week).
 */
if (!function_exists('ias_event_cards')) {
  function ias_event_cards(string $from = '', string $to = ''): array {
    $now = ias_now();
    $out = [];
    foreach (ia_stage_auctions('upcoming', 200) as $row) {
      $id = (int) $row['ID'];
      if (get_post_status($id) !== 'publish' || post_password_required($id) || get_post_meta($id, '_ias_posts_cancelled', true) !== '') continue;
      $f = ias_listing_facts($id);
      if (!ias_events_due_for($f, $now)) continue;
      $marks = ias_event_marks($id);
      foreach (ias_event_cards_for($f) as $key => $card) {
        if (isset($marks[$key]) || $card['start'] <= $now) continue;
        $day = $card['start']->format('Y-m-d');
        if (($from !== '' && $day < $from) || ($to !== '' && $day > $to)) continue;
        $card['cover'] = ias_event_cover($f);
        $out[] = $card;
      }
    }
    usort($out, function ($a, $b) { return $a['start'] <=> $b['start']; });
    return $out;
  }
}

/** Mark a card created or skipped. */
if (!function_exists('ias_event_mark')) {
  function ias_event_mark(int $listing_id, string $key, string $status, string $by): void {
    $marks = ias_event_marks($listing_id);
    $marks[$key] = ['status' => $status === 'skipped' ? 'skipped' : 'created', 'at' => ias_now_sql(), 'by' => $by];
    update_post_meta($listing_id, '_ias_fb_events', $marks);
    ias_log('edited', $listing_id, ['summary' => 'Facebook event ' . $key . ' marked ' . $marks[$key]['status']], $by);
  }
}

/** The cards in the Monday email (only while the Facebook Events switch is on). */
add_filter('ias_review_email_sections', function (string $html, DateTimeImmutable $start, DateTimeImmutable $end): string {
  if (!ias_type_on('events')) return $html;
  $cards = ias_event_cards('', $end->modify('+14 days')->format('Y-m-d'));
  $html .= '<h3 style="color:#233a66;margin:22px 0 6px;font-size:16px">Facebook Events to create (' . count($cards) . ')</h3>';
  if (!$cards) return $html . '<p>None waiting.</p>';
  $html .= '<p style="font-size:13px;color:#646970">Facebook does not let us create these automatically. In Meta Business Suite → Events → Create event, copy each one in (about a minute), then press Mark created on the Social Calendar.</p>';
  foreach ($cards as $c) {
    $html .= '<div style="border:1px solid #dcdcde;border-radius:6px;padding:10px 12px;margin:0 0 10px"><strong>' . esc_html($c['title']) . '</strong><br>'
      . esc_html($c['start']->format('D, M j, g:i a') . ' – ' . $c['end']->format('g:i a')) . '<br>' . esc_html($c['where'])
      . '<div style="white-space:pre-wrap;font-size:13px;color:#3c434a;margin-top:6px">' . esc_html($c['description']) . '</div>'
      . ($c['cover'] !== '' ? '<p style="margin:6px 0 0;font-size:13px">Cover photo: <a href="' . esc_url($c['cover']) . '">download</a></p>' : '') . '</div>';
  }
  return $html;
}, 10, 3);
