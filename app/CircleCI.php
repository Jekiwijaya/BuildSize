<?php

namespace App;

// TODO: Test CircleCI 2.0
// TODO: Generalize this

use App\Models\Build;
use App\Models\BuildArtifact;
use App\Models\GithubInstall;
use App\Models\Project;
use App\Models\ProjectArtifact;
use GuzzleHttp\Client;
use GuzzleHttp\Promise;

abstract class CircleCI {
  public static function analyzeBuildFromURL(string $url, $payload) {
    $parts = static::parseBuildURL($url);
    static::analyzeBuild($parts['owner'], $parts['repo'], $parts['build'], $payload);
  }

  public static function analyzeBuild(string $username, string $reponame, int $build_num, $payload) {
    // TODO: Parallelize these calls
    $build = static::call(
      'project/github/%s/%s/%s',
      $username,
      $reponame,
      $build_num
    );
    $artifacts = static::call(
      'project/github/%s/%s/%s/artifacts',
      $username,
      $reponame,
      $build_num
    );
    if (count($artifacts) === 0) {
      return;
    }

    $sizes = static::getArtifactSizes($artifacts);

    // TODO: Clean up all this handling, make it more generic and reusable
    // TODO: Unit test all of this stuff!
    if (count($payload['branches']) > 0) {
      // Build is on a branch, so save information for that branch
      foreach ($payload['branches'] as $branch) {
        static::saveArtifactsForProjectBuild($artifacts, $sizes, $payload, [
          'org_name' => $payload['repository']['owner']['login'],
          'repo_name' => $payload['repository']['name'],
          'identifier' => $branch['name'] . '/' . $payload['commit']['sha'],
          'build_data' => [],
        ]);
      }
    }

    // TODO: Work out if this is retrievable from GitHub rather than calling CircleCI's API for it
    if (count($build->pull_requests) > 0) {
      // Build is part of a PR, so save for all PRs too
      foreach ($build->pull_requests as $pull_request) {
        $pull_request_url = GithubUtils::parsePullRequestURL($pull_request->url);
        if ($pull_request_url !== null) {
          static::saveArtifactsForProjectBuild($artifacts, $sizes, $payload, [
            'org_name' => $pull_request_url['username'],
            'repo_name' => $pull_request_url['reponame'],
            'pull_request' => $pull_request_url['pr_number'],
            // TODO: saveArtifactsForProjectBuild should just infer this rather than having to explicitly pass it
            'identifier' => 'pr/' . $pull_request_url['pr_number'],
            'build_data' => [
              'pull_request' => $pull_request_url['pr_number'],
            ],
          ]);
        }
      }
    }
  }

  private static function saveArtifactsForProjectBuild(
    $artifacts,
    $sizes,
    $payload,
    $metadata
  ) {
    // TODO: This should handle default_branch too
    $project = Project::firstOrNew(
      [
        'host' => 'github',
        'org_name' => $metadata['org_name'],
        'repo_name' => $metadata['repo_name'],
      ]
    );
    if (!$project->exists) {
      $project->active = false;
      $project->save();
    }

    $build = Build::updateOrCreate(
      [
        'project_id' => $project->id,
        'identifier' => $metadata['identifier'],
      ],
      array_merge([
        'commit' => $payload['commit']['sha'],
        'committer' => $payload['commit']['author']['login'],
      ], $metadata['build_data'])
    );

    $project_artifact_ids = [];
    $new_project_artifacts = [];
    $artifact_names = [];

    foreach ($project->artifacts as $artifact) {
      $project_artifact_ids[$artifact->name] = $artifact->id;
    }

    foreach ($artifacts as $artifact) {
      $filename = basename($artifact->path);
      $artifact_names[$artifact->path] = ArtifactUtils::generalizeName($filename);
      if (!array_key_exists($filename, $project_artifact_ids)) {
        // This is the first time we've seen this artifact!
        $new_project_artifacts[] = new ProjectArtifact([
            'name' => $filename,
          ]
        );
      }
    }

    $project->artifacts()->saveMany($new_project_artifacts);

    // Add IDs for newly-added project artifacts
    foreach ($new_project_artifacts as $artifact) {
      $project_artifact_ids[$artifact->name] = $artifact->id;
    }

    foreach ($artifacts as $artifact) {
      $filename = basename($artifact->path);

      BuildArtifact::updateOrCreate(
        [
          'build_id' => $build->id,
          'project_artifact_id' =>
            $project_artifact_ids[$artifact_names[$artifact->path]],
        ],
        [
          'filename' => $filename,
          'size' => $sizes[$filename],
        ]
      );
    }

    // See if we have a GitHub app configured for this repo
    $install = GithubInstall::where('install_id', $payload['installation']['id'])
      ->first();
    if (!$install) {
      return;
    }

    // TODO: update status
    // TODO: Comment if PR
  }

  private static function getArtifactSizes(array $artifacts): array {
    $dir = FilesystemUtils::createTempDir('buildartifacts');

    // Download the artifacts in parallel
    $artifact_client = new Client();
    $requests = [];
    $file_handles = [];

    try {
      foreach ($artifacts as $artifact) {
        $filename = basename($artifact->path);
        $file_handle = fopen($dir . $filename, 'w');
        $requests[$filename] = $artifact_client->getAsync($artifact->url, [
            'sink' => $file_handle,
          ]
        );
        $file_handles[$filename] = $file_handle;
      }
      Promise\unwrap($requests);

      $sizes = [];
      foreach ($artifacts as $artifact) {
        $filename = basename($artifact->path);
        $sizes[$filename] = fstat($file_handles[$filename])['size'];
      }
      return $sizes;
    } finally {
      try {
        foreach ($file_handles as $file_handle) {
          fclose($file_handle);
        }
      } catch (\Exception $e) {
        // Could be locked or something... Just ignore it.
      }
      FilesystemUtils::recursiveRmDir($dir);
    }
  }

  public static function call(string $uri, ...$uri_args) {
    // TODO: Use API key?
    $client = new Client([
      'base_uri' => 'https://circleci.com/api/v1.1/',
    ]);
    $response = $client->get(vsprintf($uri, $uri_args), [
      'headers' => [
        'Accept' => 'application/json',
      ],
    ]);
    return json_decode((string)$response->getBody());
  }

  /**
   * Parses the owner, repo and build number from a CircleCI build URL.
   * @param string $url
   * @return array
   */
  public static function parseBuildURL(string $url) {
    $path = parse_url($url, PHP_URL_PATH);
    $parts = explode('/', $path);
    if (count($parts) !== 5 || $parts[1] !== 'gh') {
      throw new \InvalidArgumentException('Unexpected CircleCI URL format: ' . $path);
    }
    return [
      'owner' => $parts[2],
      'repo' => $parts[3],
      'build' => (int)$parts[4],
    ];
  }
}
