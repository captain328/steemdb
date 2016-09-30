<?php
namespace SteemDB\Controllers;

use MongoDB\BSON\UTCDateTime;

use SteemDB\Models\Account;
use SteemDB\Models\Comment;
use SteemDB\Models\Reblog;

class CommentController extends ControllerBase
{

  public function listAction()
  {
    $query = array(
      'depth' => 0,
    );
    $sort = array(
      'created' => -1,
    );
    $limit = 50;
    $this->view->comments = Comment::find(array(
      $query,
      "sort" => $sort,
      "limit" => $limit
    ));
  }

  public function curieAction()
  {
    $sort = [
      'created' => 1
    ];
    $this->view->sort = $this->request->get('sort');
    switch($this->view->sort) {
      case "newest":
        $sort = [
          'created' => -1
        ];
        break;
      case "reputation":
        $sort = [
          'account.reputation' => -1
        ];
        break;
      case "votes":
        $sort = [
          'net_votes' => -1
        ];
        break;
    }
    $splimit = (float) str_replace(',', '', explode(' ', $this->convert->sp2vest(3000))[0]);
    $this->view->comments = Comment::aggregate([
      [
        '$match' => [
          'depth' => 0,
          'mode' => 'first_payout',
          'total_pending_payout_value' => ['$lte' => 10],
          'created' => [
            '$lte' => new UTCDateTime(strtotime('-6 hours') * 1000),
            '$gte' => new UTCDateTime(strtotime('-20 hours') * 1000),
          ]
        ]
      ],
      [
        '$lookup' => [
          'from' => 'account',
          'localField' => 'author',
          'foreignField' => 'name',
          'as' => 'account'
        ]
      ],
      [
        '$match' => [
          'account.reputation' => ['$lt' => 7784855346100],
          'account.followers_count' => ['$lt' => 100],
          'account.vesting_shares' => ['$lt' => $splimit]
        ]
      ],
      [
        '$sort' => $sort
      ]
    ])->toArray();
    // echo '<pre>'; var_dump($this->view->comments[0]); exit;
  }

  public function viewAction()
  {
    // Get parameters
    $tag = $this->dispatcher->getParam("tag", "string");
    $author = $this->dispatcher->getParam("author", "string");
    $permlink = $this->dispatcher->getParam("permlink", "string");
    // Load the Post
    $query = array(
      '_id' => $author . '/' . $permlink
    );
    $comment = $this->view->comment = Comment::findFirst(array(
      $query
    ));
    if(!$comment) {
      $this->flashSession->error('The specified post does not exist on SteemDB currently.');
      $this->response->redirect();
      return;
    }
    // Sort the votes by rshares
    $votes = $comment->active_votes;
    usort($votes, function($a, $b) {
      return (string) $a->time - (string) $b->time;
    });
    $this->view->votes = $votes;
    // And get it's replies
    $query = array(
      'parent_author' => $author,
      'parent_permlink' => $permlink
    );
    $this->view->replies = Comment::find(array(
      $query,
      "sort" => ['created' => -1]
    ));
    // And get it's reblogs
    $query = array(
      'permlink' => $comment->permlink,
      'author' => $comment->author,
    );
    $this->view->reblogs = Reblog::find(array(
      $query,
      "sort" => ['_ts' => 1]
    ));
    // And finally the author
    $query = array(
      'name' => $comment->author
    );
    $this->view->author = Account::findFirst(array($query));
    // And some additional posts
    $this->view->posts = Comment::find(array(
      array(
        'author' => $comment->author,
        'depth' => 0,
      ),
      'sort' => array('created' => -1),
      'limit' => 5
    ));
  }

  public function dailyAction() {
    $sortFields = [
      "combined_payout" => -1,
    ];
    $this->view->sort = $sort = $this->dispatcher->getParam("sort");
    switch($sort) {
      case "votes":
        $sortFields = ["net_votes" => -1];
        break;
    }
    $this->view->date = $date = strtotime($this->dispatcher->getParam("date") ?: date("Y-m-d"));
    $this->view->tag = $tag = $this->dispatcher->getParam("tag", "string") ?: "all";
    $query = [
      'depth' => 0,
      'created' => [
        '$gte' => new UTCDateTime($date * 1000),
        '$lte' => new UTCDateTime(($date + 86400) * 1000),
      ],
    ];
    if($tag !== 'all') $query['category'] = $tag;
    $this->view->comments = Comment::aggregate([
      ['$match' => $query],
      ['$project' => [
        '_id' => '$_id',
        'created' => '$created',
        'url' => '$url',
        'title' => '$title',
        'author' => '$author',
        'author_reputation' => '$author_reputation',
        'category' => '$category',
        'net_votes' => '$net_votes',
        'combined_payout' => ['$add' => ['$total_payout_value', '$total_pending_payout_value']],
        'total_payout_value' => '$total_payout_value',
        'total_pending_payout_value' => '$total_pending_payout_value'
      ]],
      ['$sort' => $sortFields],
      ['$limit' => 10]
    ]);
  }

}
