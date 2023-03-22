<?php
define( 'OPENAI_TOKEN', getenv('OPENAI_API_KEY') );
define( 'GITHUB_TOKEN', getenv('GITHUB_API_KEY') );
define( 'REPO', 'artpi/autopilot' );
define( 'FILE', 'content/index.html' );

function call_api( $url, $token, $payload = '', $method = 'GET' ) {
    $options = array(
        'http' => array(
            'ignore_errors' => true,
            'method'  => $method,
            'header'  => "Content-Type: application/json\r\n" .
                         "User-Agent: artpi\r\n" .
                         "Authorization: Bearer " . $token . "\r\n",
        ),
    );

    if ( $payload ) {
        $options['http']['content'] = json_encode( $payload );
    }

    $context = stream_context_create( $options );
    $response = @file_get_contents( $url, false, $context );

    return json_decode( $response );
}

function call_gpt( $prompt = array() ) {
    $response_data = call_api(
        'https://api.openai.com/v1/chat/completions', 
        OPENAI_TOKEN,
        array(
            'model'     => 'gpt-3.5-turbo',
            'messages'  => $prompt,
            'max_tokens' => 2048,
        ),
        'POST',
    );

    if ( ! isset( $response_data->choices[0]->message->content ) ) {
        print_r( $response_data );
        return false;
    }

    return trim( $response_data->choices[0]->message->content );
}

function change( $new_instruction = '' ) {
    $system_prompt = 'You are a program that manipulates html. Please output only valid HTML. You can use Javascript, CSS and HTML5 tags. You will get previous content of the page and will manipulate it to accomodate a following instruction.';
    $previous_html = file_get_contents( FILE );
    $prompt = array(
        array(
            'role'    => 'system',
            'content' => $system_prompt,
        ),
        array(
            'role'    => 'user',
            'content' => $new_instruction,
        ),
        array(
            'role'    => 'user',
            'content' => $previous_html,
        ),
    );
    $response = call_gpt( $prompt );
    if ( $response ) {
        file_put_contents( FILE, $response );
        return true;
    }
    return false;
}

function perform_changes_from_issues() {
    $issues = call_api( 'https://api.github.com/repos/' . REPO . '/issues?state=open', GITHUB_TOKEN );
    if ( ! $issues ) {
        return;
    }
    $prompts = array_map(
        function ( $issue ) {
            return $issue->title . "\n\n" . substr( $issue->body, 0, 248 );
        }, $issues
    );

    $prompt = join( "\n\n", $prompts );

    if ( ! $prompt ) {
        return;
    }
    echo $prompt;

    $didchange = change( $prompt );

    // We are going to close issues anyway to prevent one failing issue holding up everything
    foreach ( $issues as $issue ) {
        call_api( $issue->url, GITHUB_TOKEN, array( 'state' => 'closed' ), 'PATCH' );
    }
    if( ! $didchange ) {
        return;
    }

    $closed_issues = join( ', ',  array_map( function ( $issue ) { return "#{$issue->number}"; }, $issues ) );
    commit( "Closes $closed_issues" );
}

perform_changes_from_issues();

function commit( $message ) {
    system( 'git config user.name "Autopilot"' );
    system( 'git config user.email "autopilot@artpi.net"' );
    system( 'git add ./content' );
    system( 'git commit -m "' . $message . '"' );
    system( 'git push' );
}
