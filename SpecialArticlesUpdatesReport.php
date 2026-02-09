<?php

use MediaWiki\MediaWikiServices;

class SpecialArticlesUpdatesReport extends FormSpecialPage {

	private $mDb = null,
		$tables = [],
		$conds = [],
		$fields = [],
		$options = [],
		$joinConds = [];

	/**
	 * SpecialArticlesUpdatesReport constructor.
	 *
	 * @param string $name
	 * @param string $restriction
	 */
	public function __construct( $name = 'ArticlesUpdatesReport', $restriction = 'viewreports' ) {
		parent::__construct( $name, $restriction );
	}

	/**
	 * Override execute to show results before form and make form collapsible
	 *
	 * @param string|null $par
	 */
	public function execute( $par ) {
		$this->setHeaders();
		$this->checkPermissions();
		$this->outputHeader();

		// Load the CSS module
		$this->getOutput()->addModuleStyles( 'ext.wikirights.reports' );

		$form = $this->getForm();
		$form->prepareForm();

		$result = $form->tryAuthorizedSubmit();

		// If form was successfully submitted, show results first, then collapsed form
		if ( $result === true || ( $result instanceof Status && $result->isGood() ) ) {
			// Results are output by onSubmit() - they're already displayed above
			// Now show the form collapsed below the results
			$form->displayForm( $result );
		} else {
			// First time or error - show form expanded
			$form->displayForm( $result );
		}
	}

	/**
	 * Modify the form to make it collapsible
	 *
	 * @param HTMLForm $form
	 */
	protected function alterForm( HTMLForm $form ) {
		$form->setWrapperLegendMsg( 'reports-form-legend' );

		// Check if this is a submission by checking request data
		$request = $this->getRequest();
		// Make form collapsible and collapsed after submission
		$form->setCollapsibleOptions( $request->wasPosted() );
	}

	/**
	 * @return array
	 */
	protected function getFormFields() {
		return [
			'from' => [
				'type' => 'date',
				'name' => 'from',
				'label-message' => 'reports-field-from'
			],
			'to' => [
				'type' => 'date',
				'name' => 'to',
				'value' => null,
				'label-message' => 'reports-field-to'
			],
			'category' => [
				'class' => 'HTMLTitleTextField',
				'name' => 'category',
				'label-message' => 'reports-field-category',
				'namespace' => NS_CATEGORY,
				'relative' => true,
				'exists' => true,
				'required' => false,
				// 'validation-callback' => [ $this, 'validateCategoryField' ],
			],
			'debug' => [
				'type' => 'check',
				'name' => 'debug',
				'label-message' => 'reports-field-debug',
				'help-message' => 'reports-field-debug-help'
			]
		];
	}

	/**
	 * @param array $data
	 *
	 * @return array|bool|Status|string
	 */
	public function onSubmit( array $data ) {
		$status = self::requireAtLeastOneParameter( $data, 'from', 'to' );

		if ( $status->isGood() ) {
			$out = $this->getOutput();
			$count = $this->getCount( $data );

			// Emphasize the main result
			$out->addHTML( '<div class="reports-result-box">' );
			$out->addWikiMsg( 'reports-number-of-articles-updated', '<strong>' . $count . '</strong>' );
			$out->addHTML( '</div>' );

			// Show debug info only if debug checkbox is checked
			if ( !empty( $data['debug'] ) ) {
				// Get editors WITHOUT bot filtering to see who would be included
				$allEditors = $this->getDistinctEditors( $data, false );

				// Get editors WITH bot filtering (the actual result)
				$humanEditors = $this->getDistinctEditors( $data, true );

				// Get list of bot actor names
				$dbr = $this->getDB();
				$ignoredActors = $dbr->select(
					[ 'user_groups', 'actor' ],
					'actor_name',
					[ 'ug_group' => [ 'automaton' ] ],
					__METHOD__,
					[ 'GROUP BY' => 'actor_name' ],
					[ 'actor' => [ 'JOIN', 'actor_user = ug_user' ] ]
				);

				$botNames = [];
				foreach ( $ignoredActors as $actor ) {
					$botNames[] = $actor->actor_name;
				}
				$botNamesSet = array_flip( $botNames );

				// Separate editors into humans and bots
				$humans = [];
				$bots = [];
				foreach ( $allEditors as $editor ) {
					if ( isset( $botNamesSet[$editor] ) ) {
						$bots[] = $editor;
					} else {
						$humans[] = $editor;
					}
				}

				// Display compact summary
				$out->addHTML( '<div class="reports-debug-section">' );
				$out->addHTML( '<h3>' . $this->msg( 'reports-debug-editor-details' )->escaped() . '</h3>' );
				$out->addHTML( '<p><strong>' . $this->msg( 'reports-distinct-users' )->escaped() . ':</strong> ' .
					$this->msg( 'reports-people-and-bots' )
						->numParams( count( $humans ), count( $bots ) )
						->escaped() );
				$out->addHTML( '</p>' );

				// Display numbered list with bots marked as strikethrough
				$out->addHTML( '<p><strong>' . $this->msg( 'reports-names' )->escaped() . ':</strong></p>' );
				$out->addHTML( '<ol class="reports-editor-list">' );

				// Show humans first
				foreach ( $humans as $editor ) {
					$out->addHTML( '<li>' . htmlspecialchars( $editor ) . '</li>' );
				}

				// Then show bots with strikethrough
				foreach ( $bots as $editor ) {
					$out->addHTML( '<li><del>' . htmlspecialchars( $editor ) . '</del></li>' );
				}

				$out->addHTML( '</ol>' );
				$out->addHTML( '</div>' );
			}

			return true;
		} else {
			return $status;
		}
	}

	/**
	 * @return \Wikimedia\Rdbms\Database
	 */
	private function getDB() {
		return $this->mDb ?? wfGetDB( DB_REPLICA );
	}

	/**
	 * @param array $data
	 *
	 * @return int Number of matching rows in the database
	 * @throws Exception
	 */
	protected function getCount( $data ) {
		$dbr = $this->getDB();
		$actorsToIgnoreQuery = $this->getSubqueryIgnoredActors();

		$this->tables = [ 'revision', 'page' ];
		$this->fields = [
			'count' => 'COUNT(DISTINCT rev_page)',
		];
		// Reset conditions to rebuild them
		$this->conds = [];
		$this->joinConds = [];
		$this->options = [];

		self::limitByCategory( $data );
		self::limitByDates( $data );

		$this->conds[ 'page_namespace' ] = NS_MAIN;
		$this->conds[ 'page.page_is_redirect' ] = 0;
		// We want updates, not creation of articles
		$this->conds[] = 'revision.rev_parent_id != 0';

		// Make sure this is not a replacetext edit - exclude mass edits
		// Try to make this apply to multiple languages (he, ar, en) by exploding the edit summary
		$msgText = $this->msg( 'replacetext_editsummary' )->text();
		$msgText = strtok( $msgText, '-–' );
		$commentStore = MediaWikiServices::getInstance()->getCommentStore();
		$commentQuery = $commentStore->getJoin( 'rev_comment' );
		$this->conds[] = 'comment_text NOT' . $dbr->buildLike( $msgText, $dbr->anyString() );

		// Ignore edits by certain users
		$actorQuery = ActorMigration::newMigration()->getJoin( 'rev_user' );
		$this->conds[] = $actorQuery['fields']['rev_user'] . ' NOT IN (' . $actorsToIgnoreQuery . ')';

		$this->joinConds['page'] = [ 'LEFT JOIN', 'rev_page=page_id' ];

		$result = $dbr->select(
			$this->tables + $commentQuery['tables'] + $actorQuery['tables'],
			$this->fields + $commentQuery['fields'] + $actorQuery['fields'],
			$this->conds,
			__METHOD__,
			$this->options,
			$this->joinConds + $commentQuery['joins'] + $actorQuery['joins']
		);

		$row = $result->fetchRow();
		return (int)$row['count'];
	}

	/**
	 * Get list of distinct editor names who made updates
	 *
	 * @param array $data
	 * @param bool $excludeBots Whether to exclude bot users (default true)
	 * @return array Array of editor names
	 * @throws Exception
	 */
	protected function getDistinctEditors( $data, $excludeBots = true ) {
		$dbr = $this->getDB();
		$actorsToIgnoreQuery = $this->getSubqueryIgnoredActors();

		$this->tables = [ 'revision', 'page' ];
		$this->fields = [
			'user_name' => 'actor_name',
		];

		// Reset conditions to rebuild them
		$this->conds = [];
		$this->joinConds = [];
		$this->options = [];

		self::limitByCategory( $data );
		self::limitByDates( $data );

		$this->conds[ 'page_namespace' ] = NS_MAIN;
		$this->conds[ 'page.page_is_redirect' ] = 0;
		$this->conds[] = 'revision.rev_parent_id != 0';

		// Exclude replacetext edits
		$msgText = $this->msg( 'replacetext_editsummary' )->text();
		$msgText = strtok( $msgText, '-–' );
		$commentStore = MediaWikiServices::getInstance()->getCommentStore();
		$commentQuery = $commentStore->getJoin( 'rev_comment' );
		$this->conds[] = 'comment_text NOT' . $dbr->buildLike( $msgText, $dbr->anyString() );

		// Ignore edits by certain users (only if excludeBots is true)
		$actorQuery = ActorMigration::newMigration()->getJoin( 'rev_user' );
		if ( $excludeBots ) {
			$this->conds[] = $actorQuery['fields']['rev_user'] . ' NOT IN (' . $actorsToIgnoreQuery . ')';
		}

		$this->joinConds['page'] = [ 'LEFT JOIN', 'rev_page=page_id' ];

		// Use GROUP BY to get distinct editor names
		$this->options['GROUP BY'] = 'actor_name';
		$this->options['ORDER BY'] = 'actor_name';

		$result = $dbr->select(
			$this->tables + $commentQuery['tables'] + $actorQuery['tables'],
			$this->fields,
			$this->conds,
			__METHOD__,
			$this->options,
			$this->joinConds + $commentQuery['joins'] + $actorQuery['joins']
		);

		$editors = [];
		foreach ( $result as $row ) {
			$editors[] = $row->user_name;
		}

		return $editors;
	}

	/**
	 * @return string SQL subquery
	 */
	protected function getSubqueryIgnoredActors() {
		$dbr = wfGetDB( DB_REPLICA );

		$nonHumanGroups = [ 'automaton' ];

		return $dbr->selectSQLText(
			[ 'user_groups', 'actor' ],
			'actor_user',
			[ 'ug_group' => $nonHumanGroups ],
			__METHOD__,
			[ 'GROUP BY' => [ 'actor_user' ] ],
			[ 'actor' => [ 'JOIN', 'actor_user = ug_user' ] ]
		);
	}

	/**
	 * @param array $data
	 */
	protected function limitByDates( $data ) {
		$db = $this->getDB();
		$from = $data[ 'from' ];
		$to = $data[ 'to' ];
		$fieldName = 'rev_timestamp';

		if ( !empty( $from ) ) {
			$this->conds[] = $fieldName . ' >= ' . $db->addQuotes( $db->timestamp( new DateTime( $from ) ) );
		}
		if ( !empty( $to ) ) {
			// Add 1 day, so we check for "any date before tomorrow"
			$to = $db->timestamp( new DateTime( $to . ' +1 day' ) );
			$this->conds[] = $fieldName . ' < ' . $db->addQuotes( $to );
		}
	}

	/**
	 * @param array $data
	 * @throws ErrorPageError
	 */
	protected function limitByCategory( $data ) {
		if ( empty( $data[ 'category' ] ) ) {
			return;
		}
		$titleParser = MediaWikiServices::getInstance()->getTitleParser();
		try {
			$categoryTitleValue = $titleParser->parseTitle( $data['category'], NS_CATEGORY );
		} catch ( MalformedTitleException $e ) {
			throw new ErrorPageError( $e->getTitleText(), $e->getMessage() );
		}
		$this->tables[] = 'categorylinks';
		$this->joinConds['categorylinks'] = [ 'INNER JOIN',
			[
				'rev_page = cl_from',
				'cl_to' => $categoryTitleValue->getDBkey()
			]
		];
	}

	/**
	 * Copied from ApiBase::requireAtLeastOneParameter(), but also makes sure  no '' values are given
	 *
	 * Die if none of a certain set of parameters is set and not false.
	 *
	 * @param array $params User provided set of parameters
	 * @param string ...$required Names of parameters of which at least one must be set
	 *
	 * @return Status
	 */
	public function requireAtLeastOneParameter( $params, ...$required ) {
		$intersection = array_intersect(
			array_keys( array_filter( $params, [ $this, 'parameterNotEmpty' ] ) ),
			$required
		);

		if ( count( $intersection ) == 0 ) {
			return Status::newFatal(
				'reports-missingparam-at-least-one-of',
				Message::listParam( array_map(
					function ( $p ) {
						return '<var>' . $this->msg( 'reports-field-' . $p )->escaped() . '</var>';
					},
					array_values( $required )
				),
				'comma' ),
				count( $required )
			);
		}

		return Status::newGood();
	}

	/**
	 * Based on the private ApiBase::parameterNotEmpty(), but adding a check for an empty string
	 *
	 * Callback function used in requireOnlyOneParameter to check whether required parameters are set
	 *
	 * @param string|int|null $x Parameter to check is not null/false
	 * @return bool
	 */
	private function parameterNotEmpty( $x ) {
		return $x !== null && $x !== false && $x !== '';
	}

	/**
	 * @return bool
	 */
	public function requiresWrite() {
		return false;
	}

	/**
	 * @return string
	 */
	protected function getDisplayFormat() {
		return 'ooui';
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'changes';
	}
}
