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
                         "Authorization: Bearer " . $token . "\r\n",
        ),
    );

    if ( $payload ) {
        $options['http']['content'] = json_encode( $payload );
    }

    $context = stream_context_create( $options );
    $response = @file_get_contents( $url, false, $context );
    if( ! $response ) {
        echo "Empty response from OpenAI! \n";
        return false;
    }
    print_r( $response );
    return json_decode( $response );
}

function call_gpt( $prompt = array() ) {
    print_r( $prompt );
    $response_data = call_api(
        'https://api.openai.com/v1/chat/completions', 
        OPENAI_TOKEN,
        array(
            'model'     => 'gpt-4',
            'messages'  => $prompt,
            'max_tokens' => 2048,
        ),
        'POST',
    );

    if ( ! $response_data || ( isset( $response_data->error->type ) && $response_data->error->type === 'server_error' ) ) {
        echo "GPT4 rate limited. Let's fall back to chat gpt api.";
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
    }

    print_r( $response_data );
    if ( ! isset( $response_data->choices[0]->message->content ) ) {
        echo "Empty response. Aborting\n";
        exit( 1 );
    }
    $html = trim( $response_data->choices[0]->message->content );

    if ( strpos( $html, '<!DOCTYPE html>' ) !== 0 ) {
        // GPT respondend not with invalid HTML. We abort.
        echo "Invalid HTML. Aborting\n";
        exit( 1 );
    }
    return $html;
}

function call_dalle( $prompt ) {
    $filename = strtolower( preg_replace( '/[^a-zA-Z]/', '_', $prompt ) ) . '.png';
    $relative = "images/" . $filename;
    $path = "content/$relative";


    if ( file_exists( $path ) ) {
        echo "image exists";
        return $relative;
    }

    $response_data = call_api(
        'https://api.openai.com/v1/images/generations', 
        OPENAI_TOKEN,
        array(
            'n'     => 1,
            'prompt' => $prompt,
            'size'  => '256x256',
        ),
        'POST',
    );

    if ( ! isset( $response_data->data[0]->url ) ) {
        print_r( $response_data );
        return false;
    }
    
    copy( $response_data->data[0]->url, $path );
    echo "Created image $path\n";
    return $relative;
}

function change( $new_instruction = '' ) {
    $system_prompt = 'You are a program that manipulates html. Please output only valid HTML. You can use Javascript, CSS and HTML5 tags. You will get previous content of the page and will manipulate it to accomodate a following instructions:';
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
            'content' => "Here is the previous HTML. Please only output HTML in return: ```\n{$previous_html}\n```",
        ),
    );

    $response = call_gpt( $prompt );
    if ( $response ) {
        // Now let's check images!
        preg_match_all( '#<img src="([^"]+)" alt="([^"]+)" \/>#is', $response, $images );
        $used_dalle = false;
        foreach( $images[1] as $key => $image_url ) {
            if( file_exists( 'content/' . $image_url ) ) {
                // image already is there.
            } else if ( $used_dalle ) {
                // We are going to just remove this image since we cannot run arround burning our dalle credits.
                $response = str_replace( $images[0][$key], '', $response );
            } else {
                $used_dalle = true;
                echo "calling dalle with {$images[2][$key]}\n";
                $image_url = call_dalle( $images[2][$key] );
                if ( $image_url ) {
                    $response = str_replace( $images[1][$key], $image_url, $response );
                }
            }
            
        }

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

function commit( $message ) {
    system( 'git config user.name "Autopilot"' );
    system( 'git config user.email "autopilot@artpi.net"' );
    system( 'git add ./content' );
    system( 'git commit -m "' . $message . '"' );
    system( 'git push' );
}

perform_changes_from_issues();
