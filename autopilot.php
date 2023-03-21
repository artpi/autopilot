<?php
define( 'OPENAI_TOKEN', getenv('OPENAI_API_KEY') );
define( 'GITHUB_TOKEN', getenv('GITHUB_API_KEY') );
define( 'REPO', 'artpi/autopilot' );
define( 'FILE', 'content/index.html' );


function callGithub( $url, $payload ) {
    $ch = curl_init( $url );
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

    $headers = array(
        'Content-Type:application/json',
        'User-Agent: artpi',
        'Authorization: Bearer ' . GITHUB_TOKEN
    );        
    if ( $payload ) {
        // We got a PATCH!
        // Attach encoded JSON string to the POST fields
        curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $payload ) );
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH' );

    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers );
    $return = curl_exec($ch);
    curl_close( $ch );
    return $return;
}

function callGPT( $prompt = [] ) {
    // GPT-3 API endpoint
    $url = "https://api.openai.com/v1/chat/completions";

    // Request options
    $options = [
        "http" => [
            "method" => "POST",
            "header" => "Content-Type: application/json\r\n" .
                        "Authorization: Bearer " . OPENAI_TOKEN . "\r\n",
            "content" => json_encode([
                "model" => "gpt-4",
                "messages" => $prompt,
                "max_tokens" => 2048
            ])
        ]
    ];

    // Create a stream context
    $context = stream_context_create($options);

    // Send the request
    $response = file_get_contents($url, false, $context);

    // Decode the response
    $response_data = json_decode($response, true);

    if( ! isset( $response_data['choices'][0]['message']['content'] ) ) {
        print_r( $response_data );
        return false;
    }

    // Print the generated text
    return trim( $response_data['choices'][0]['message']['content'] );
}

function change( $new_instruction = '' ) {
    $system_prompt = 'You are a program that manipulates html. Please output only valid HTML. You can use Javascript, CSS and HTML5 tags. You will get previous content of the page and will manipulate it to accomodate a following instruction.';
    $previous_html = file_get_contents( FILE );
    $prompt = [
        [
            'role' => 'system',
            'content' => $system_prompt,
        ],
        [
            'role' => 'user',
            'content' => $new_instruction,
        ],
        [
            'role' => 'user',
            'content' => $previous_html,
        ]
    ];
    $response = callGPT( $prompt );
    if( $response ) {
        file_put_contents( FILE, $response );
    }

}

function performChangesFromIssues() {
    $response = callGithub( 'https://api.github.com/repos/' . REPO . '/issues?state=open', [] );
    $issues = json_decode( $response );
    if( ! $issues ) {
        return;
    }
    $prompts = array_map( function( $issue ) {
        return $issue->title . "\n\n" . substr( $issue->body, 0, 248 );
    }, $issues );

    $prompt = join( "\n\n", $prompts );

    if ( ! $prompt ) {
        return;
    }
    echo $prompt;

    change( $prompt );

    foreach( $issues as $issue ) {
        callGithub( $issue->url, [ 'state' => 'closed' ] );
    }
}

performChangesFromIssues();