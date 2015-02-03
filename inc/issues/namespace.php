<?php

namespace HM\Slack\Bot\Issues;

use HM\Slack;

/**
 * Get data for a single issue
 *
 * @param string $repo "owner/repo" style repo name
 * @param int $issue Issue number
 * @return array|WP_Error WP Http response on success, error otherwise.
 */
function get_issue_data( $repo, $issue ) {
	$url = sprintf( 'https://api.github.com/repos/%s/issues/%d', $repo, $issue );
	$args = array(
		'headers' => array(
			'Authorization' => sprintf( 'token %s', HM_SLACK_GITHUB_TOKEN ),
		),
	);

	$response = wp_remote_get( $url, $args );
	return $response;
}

function get_repo_for_channel( $channel ) {
	switch ( $channel ) {
		case 'owains-humble-hovel':
		case 'happytables':
		case 'happytables-dev':
		case 'happytables-growth':
			return 'humanmade/happytables';

		case 'servers':
			return 'humanmade/hm-master';

		case 'backupwordpress':
			return 'humanmade/backupwordpress';

		default:
			return null;
	}
}

function format_issue_as_attachment( $repo, $issue ) {
	$title = sprintf( '#%d: %s', $issue->number, Slack\escape( $issue->title ) );
	$data = array(
		'title'      => $title,
		'title_link' => $issue->html_url,

		'text'       => Slack\escape( $issue->body ),

		'author_name' => $issue->user->login,
		'author_link' => $issue->user->html_url,
		'author_icon' => $issue->user->avatar_url,

		'fields'  => array(),
	);

	// Issue labels
	$labels = array_map( function ( $label ) use ( $repo ) {
		// Generate our own html_url, as it's not available
		$url = sprintf( 'https://github.com/%s/labels/%s', $repo, urlencode( $label->name ) );
		return Slack\link( $url, $label->name );
	}, $issue->labels );

	if ( ! empty( $labels ) ) {
		$data['fields'][] = array(
			'title' => 'Labels',
			'value' => implode( ', ', $labels ),
			'short' => false,
		);
	}

	// Issue milestone
	if ( $issue->milestone ) {
		// Generate our own html_url, as it's not available
		$url = sprintf( 'https://github.com/%s/milestones/%s', $repo, urlencode( $issue->milestone->title ) );
		$milestone = Slack\link( $url, $issue->milestone->title );
	}
	else {
		$milestone = 'None';
	}

	$data['fields'][] = array(
		'title' => 'Milestone',
		'value' => $milestone,
		'short' => true,
	);

	// Issue owner
	if ( $issue->assignee ) {
		$owner = Slack\link( $issue->assignee->html_url, $issue->assignee->login );
	}
	else {
		$owner = 'None';
	}

	$data['fields'][] = array(
		'title' => 'Owner',
		'value' => $owner,
		'short' => true,
	);

	return $data;
}

function parse_issue_message( $responses, $data ) {
	$matched = preg_match_all( '/(?:^|\s)#(\d+)\b/', $data['text'], $all_matches, PREG_SET_ORDER );
	if ( ! $matched ) {
		return;
	}

	foreach ( $all_matches as $matches ) {
		$issue_num = absint( $matches[1] );
		$channel = $data['channel_name'];
		$repo = get_repo_for_channel( $channel );
		if ( empty( $repo ) ) {
			continue;
		}

		$response = get_issue_data( $repo, $issue_num );
		if ( is_wp_error( $response ) ) {
			continue;
		}
		if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
			continue;
		}

		$issue = json_decode( $response['body'] );
		if ( $issue === null ) {
			continue;
		}

		$responses[] = format_issue_as_attachment( $repo, $issue );
	}

	return $responses;
}

function parse_issue_link( $responses, $data ) {
	$matched = preg_match_all( '#(?:^|\b)https?://github\.com/(\w+)/(\w+)/(?:issue|pull)/(\d+)\b#i', $data['text'], $all_matches, PREG_SET_ORDER );
	if ( ! $matched ) {
		return;
	}

	foreach ( $all_matches as $matches ) {
		$repo = sprintf( '%s/%s', $matches[1], $matches[2] );
		$issue_num = absint( $matches[3] );

		$response = get_issue_data( $repo, $issue_num );
		if ( is_wp_error( $response ) ) {
			continue;
		}
		if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
			continue;
		}

		$issue = json_decode( $response['body'] );
		if ( $issue === null ) {
			continue;
		}

		$responses[] = format_issue_as_attachment( $repo, $issue );
	}

	return $responses;
}
