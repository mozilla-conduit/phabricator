<?php


/**
 * Turns feed stories into the EmailEvent list returned by the
 * feed.for_email.* query endpoints.
 *
 * This lives outside the Conduit methods so that the chronologicalKey-based
 * (feed.for_email.query) and id-based (feed.for_email.query_id) endpoints share
 * one implementation and can only differ in how they page the feed.
 */
class EmailEventBuilder {
  private PhabricatorUserStore $userStore;
  private BugStore $bugStore;
  private PhabricatorDiffStore $diffStore;
  private bool $includeFeedId;

  /**
   * @param bool $includeFeedId Emit EmailIDEvents, which carry the feed story
   *   ID. Only feed.for_email.query_id wants these; feed.for_email.query must
   *   keep returning exactly the fields it returns today.
   */
  public function __construct(PhabricatorUserStore $userStore, bool $includeFeedId = false) {
    $this->userStore = $userStore;
    $this->bugStore = new BugStore();
    $this->diffStore = new PhabricatorDiffStore();
    $this->includeFeedId = $includeFeedId;
  }

  /**
   * @param PublicEmailContext|SecureEmailContext|null $context
   */
  private function newEvent(PhabricatorStory $story, bool $isSecure, MinimalEmailContext $minimalContext, $context): EmailEvent {
    if ($this->includeFeedId) {
      return new EmailIDEvent($story->key, $story->id, $story->timestamp, $isSecure, $minimalContext, $context);
    }

    return new EmailEvent($story->key, $story->timestamp, $isSecure, $minimalContext, $context);
  }

  /**
   * @param PhabricatorStory[] $stories
   * @return EmailEvent[]
   */
  public function buildEvents(array $stories): array {
    $bugStore = $this->bugStore;
    $diffStore = $this->diffStore;
    $userStore = $this->userStore;

    $emailEvents = [];
    foreach ($stories as $story) {
      $rawRevision = $story->revision;

      /** @var array $revisionProjects */
      $revisionProjects = PhabricatorEdgeQuery::loadDestinationPHIDs(
        $rawRevision->getPHID(),
        PhabricatorProjectObjectHasProjectEdgeType::EDGECONST
      );

      if (!$revisionProjects) {
        $isSecure = false;
      } else {
        $secureTag = (new PhabricatorProjectQuery())
          ->setViewer(PhabricatorUser::getOmnipotentUser())
          ->withNames(['secure-revision'])
          ->executeOne();
        $isSecure = in_array($secureTag->getPHID(), $revisionProjects);
      }

      $actorEmail = $story->actor->loadPrimaryEmailAddress();
      $resolveUsers = new ResolveUsers($rawRevision, $actorEmail, $userStore);
      $resolveComments = new ResolveComments($story->transactions, $rawRevision, $userStore);
      $resolveCodeChange = new ResolveCodeChange($story->transactions, $rawRevision, $diffStore);
      $resolveRepositoryDetails = new ResolveRepositoryDetails();
      $resolveRevisionStatus = new ResolveRevisionStatus($rawRevision);

      // Resolve information that is needed for "minimal context" emails.
      $minimalContext = new MinimalEmailContext(MinimalEmailRevision::from($rawRevision), $resolveUsers->resolveAllPossibleRecipients());

      // I don't really like this stateful-ness (using $securePings in the "if ($isSecure)" blocks, using
      // $publicPings in the "else {}" down below). However, I don't want to duplicate the big
      // "if ($eventKind->publicKind == ...)" tree.
      // We _could_ do some magic where we add a
      // "static function build($resolveRecipients, $resolveComments, ...): EventClass" to each event, then
      // reflect on the EventKind to dynamically call this static method. However, this would be pretty "magical" and
      // hard to understand, so  I'm not sure it's worth doing to remove this small bit of method state.
      if ($isSecure) {
        $securePings = new SecureEventPings();
      } else {
        $publicPings = new PublicEventPings();
      }

      $eventKind = $story->eventKind;
      try {
        if ($eventKind->publicKind == EventKind::$ABANDON) {
          if ($isSecure) {
            $comments = $resolveComments->resolveSecureComments($securePings);
            $body = new SecureEmailRevisionAbandoned(
              $resolveUsers->resolveReviewers(false),
              $resolveUsers->resolveSubscribersAsRecipients(),
              $comments->count,
              $story->getTransactionLink()
            );
          } else {
            $comments = $resolveComments->resolvePublicComments($publicPings);
            $body = new EmailRevisionAbandoned(
              $comments->mainCommentMessage,
              $comments->inlineComments,
              $story->getTransactionLink(),
              $resolveUsers->resolveReviewers(false),
              $resolveUsers->resolveSubscribersAsRecipients()
            );
          }
        } else if ($eventKind->publicKind == EventKind::$RECLAIM) {
          $reviewers = $resolveUsers->resolveReviewers(true);
          if ($isSecure) {
            $comments = $resolveComments->resolveSecureComments($securePings);
            $body = new SecureEmailRevisionReclaimed(
              $reviewers,
              $resolveUsers->resolveSubscribersAsRecipients(),
              $comments->count,
              $story->getTransactionLink()
            );
          } else {
            $comments = $resolveComments->resolvePublicComments($publicPings);
            $body = new EmailRevisionReclaimed(
              $comments->mainCommentMessage,
              $comments->inlineComments,
              $story->getTransactionLink(),
              $reviewers,
              $resolveUsers->resolveSubscribersAsRecipients(),
            );
          }
        } else if ($eventKind->publicKind == EventKind::$COMMENT) {
          if ($isSecure) {
            $resolveComments->resolveSecureComments($securePings);
            $body = new SecureEmailRevisionCommented(
              $resolveUsers->resolveSubscribersAsRecipients(),
              $resolveUsers->resolveReviewers(false),
              $resolveUsers->resolveAuthorAsRecipient(),
              $story->getTransactionLink()
            );
          } else {
            $comments = $resolveComments->resolvePublicComments($publicPings);
            $body = new EmailRevisionCommented(
              $story->getTransactionLink(),
              $comments->mainCommentMessage,
              $comments->inlineComments,
              $resolveUsers->resolveSubscribersAsRecipients(),
              $resolveUsers->resolveReviewers(false),
              $resolveUsers->resolveAuthorAsRecipient()
            );
          }
        } else if ($eventKind->publicKind == EventKind::$CLOSE) {
          if ($isSecure) {
            $comments = $resolveComments->resolveSecureComments($securePings);
            $body = new SecureEmailRevisionClosed(
              $resolveUsers->resolveSubscribersAsRecipients(),
              $resolveUsers->resolveReviewers(false),
              $resolveUsers->resolveAuthorAsRecipient(),
              $comments->count,
              $story->getTransactionLink()
            );
          } else {
            $comments = $resolveComments->resolvePublicComments($publicPings);
            $body = new EmailRevisionClosed(
              $comments->mainCommentMessage,
              $comments->inlineComments,
              $story->getTransactionLink(),
              $resolveUsers->resolveSubscribersAsRecipients(),
              $resolveUsers->resolveReviewers(false),
              $resolveUsers->resolveAuthorAsRecipient()
            );
          }
        } else if ($eventKind->publicKind == EventKind::$LAND) {
          if ($isSecure) {
            $body = new SecureEmailRevisionLanded(
              $resolveUsers->resolveSubscribersAsRecipients(),
              $resolveUsers->resolveReviewers(false),
              $resolveUsers->resolveAuthorAsRecipient(),
            );
          } else {
            $body = EmailRevisionLanded::from(
              $resolveUsers,
              $resolveRepositoryDetails,
              $story->transactions,
              $rawRevision,
            );
          }
        } else if ($eventKind->publicKind == EventKind::$REJECT) {
          if ($isSecure) {
            $comments = $resolveComments->resolveSecureComments($securePings);
            $body = new SecureEmailRevisionRequestedChanges(
              $story->getTransactionLink(),
              $resolveUsers->resolveSubscribersAsRecipients(),
              $resolveUsers->resolveReviewers(false),
              $resolveUsers->resolveAuthorAsRecipient(),
              $comments->count
            );
          } else {
            $comments = $resolveComments->resolvePublicComments($publicPings);
            $body = new EmailRevisionRequestedChanges(
              $story->getTransactionLink(),
              $comments->mainCommentMessage,
              $comments->inlineComments,
              $resolveUsers->resolveSubscribersAsRecipients(),
              $resolveUsers->resolveReviewers(false),
              $resolveUsers->resolveAuthorAsRecipient()
            );
          }
        } else if ($eventKind->publicKind == EventKind::$REQUEST_REVIEW) {
          $reviewers = $resolveUsers->resolveReviewers(true);
          if ($isSecure) {
            $comments = $resolveComments->resolveSecureComments($securePings);
            $body = new SecureEmailRevisionRequestedReview(
              $reviewers,
              $resolveUsers->resolveSubscribersAsRecipients(),
              $comments->count,
              $story->getTransactionLink()
            );
          } else {
            $comments = $resolveComments->resolvePublicComments($publicPings);
            $body = new EmailRevisionRequestedReview(
              $comments->mainCommentMessage,
              $comments->inlineComments,
              $story->getTransactionLink(),
              $reviewers,
              $resolveUsers->resolveSubscribersAsRecipients(),
            );
          }
        } else if ($eventKind->publicKind == EventKind::$CREATE) {
          $reviewers = $resolveUsers->resolveReviewers(true);
          if ($isSecure) {
            $body = new SecureEmailRevisionCreated(
              $reviewers,
              $resolveUsers->resolveSubscribersAsRecipients()
            );
          } else {
            $body = new EmailRevisionCreated(
              $resolveCodeChange->resolveAffectedFiles(),
              $reviewers,
              $resolveUsers->resolveSubscribersAsRecipients()
            );
          }
        } else if ($eventKind->publicKind == EventKind::$ACCEPT) {
          if ($isSecure) {
            $comments = $resolveComments->resolveSecureComments($securePings);
            $body = new SecureEmailRevisionAccepted(
              $resolveRevisionStatus->resolveLandoLink(),
              $resolveRevisionStatus->resolveIsReadyToLand(),
              $resolveUsers->resolveSubscribersAsRecipients(),
              $resolveUsers->resolveReviewers(false),
              $resolveUsers->resolveAuthorAsRecipient(),
              $comments->count,
              $story->getTransactionLink()
            );
          } else {
            $comments = $resolveComments->resolvePublicComments($publicPings);
            $body = new EmailRevisionAccepted(
              $comments->mainCommentMessage,
              $comments->inlineComments,
              $story->getTransactionLink(),
              $resolveRevisionStatus->resolveLandoLink(),
              $resolveRevisionStatus->resolveIsReadyToLand(),
              $resolveUsers->resolveSubscribersAsRecipients(),
              $resolveUsers->resolveReviewers(false),
              $resolveUsers->resolveAuthorAsRecipient()
            );
          }
        } else if ($eventKind->publicKind == EventKind::$UPDATE) {
          $reviewers = $resolveUsers->resolveReviewers($resolveRevisionStatus->resolveIsNeedingReview());
          if (!$resolveCodeChange->resolveAnyDiffChanges()) {
            // There were no changes as part of this update, so don't
            // send an email for it.
            continue;
          }
          if ($isSecure) {
            $body = new SecureEmailRevisionUpdated(
              $resolveRevisionStatus->resolveLandoLink(),
              $resolveCodeChange->resolveNewChangesLink(),
              $resolveRevisionStatus->resolveIsReadyToLand(),
              $reviewers,
              $resolveUsers->resolveSubscribersAsRecipients()
            );
          } else {
            $body = new EmailRevisionUpdated(
              $resolveCodeChange->resolveAffectedFiles(),
              $resolveRevisionStatus->resolveLandoLink(),
              $resolveCodeChange->resolveNewChangesLink(),
              $resolveRevisionStatus->resolveIsReadyToLand(),
              $reviewers,
              $resolveUsers->resolveSubscribersAsRecipients()
            );
          }
        } else if ($eventKind->publicKind == EventKind::$METADATA_EDIT) {
          // There's no secret information in this event itself, so we don't differentiate
          // between "secure" and "insecure" variants
          $body = SecureEmailRevisionMetadataEdited::from(
            $resolveUsers,
            $resolveRevisionStatus,
            $story->transactions,
            $rawRevision,
            $userStore,
            $actorEmail
          );
        } else {
          continue;
        }

        $actor = Actor::from($story->actor);
        $createSecureContext = function ($kind, $body) use ($actor, $rawRevision, $bugStore) {
          return new SecureEmailContext($kind, $actor, SecureEmailRevision::from($rawRevision, $bugStore), $body);
        };
        $createPublicContext = function ($kind, $body) use ($actor, $rawRevision, $bugStore, $resolveRepositoryDetails) {
          return new PublicEmailContext($kind, $actor, EmailRevision::from($rawRevision, $bugStore, $resolveRepositoryDetails), $body);
        };

        $emailContexts = [];
        if ($isSecure) {
          $emailContexts[] = $createSecureContext($eventKind->publicKind, $body);
          foreach ($securePings->intoBodies($actorEmail, $story->getTransactionLink()) as $body) {
            $emailContexts[] = $createSecureContext(EventKind::$PINGED, $body);
          }
        } else {
          $emailContexts[] = $createPublicContext($eventKind->publicKind, $body);
          foreach ($publicPings->intoBodies($actorEmail, $story->getTransactionLink()) as $body) {
            $emailContexts[] = $createPublicContext(EventKind::$PINGED, $body);
          }
        }

        foreach ($emailContexts as $context) {
          $emailEvents[] = $this->newEvent($story, $isSecure, $minimalContext, $context);
        }
      }
      catch (Throwable $e) {
        // An error occurred while getting contextual information for this story's emails.
        // This could have been caused by issues within our custom Phabricator Emails logic,
        // or could be because a recent Phabricator change has broken an existing expectation.
        //
        // Report the error the Sentry, then send a single "minimal" email that:
        // * Contains so little information that it's unlikely to fail
        // * Contains enough information so it's actionable for the recipient - it's expected
        //   that the user will want to manually view the revision on Phabricator to see the
        //   changes that the emails weren't able to display.
        SentryLoggerPlugin::handleError(PhutilErrorHandler::EXCEPTION, $e, []);
        error_log($e);

        // "minimal" emails have no security-sensitive context, so they can be
        // considered "isSecure: false"
        $emailEvents[] = $this->newEvent($story, false, $minimalContext, null);
      }
    }

    return $emailEvents;
  }
}
