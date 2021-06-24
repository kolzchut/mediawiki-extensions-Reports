<?php

// @todo: add logic for limiting changes to category

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
				'class' => 'HTMLTitleTextFieldFixed',
				'name' => 'category',
				'label-message' => 'reports-field-category',
				'namespace' => NS_CATEGORY,
				'relative' => true,
				'exists' => true,
				'required' => false,
				// 'validation-callback' => [ $this, 'validateCategoryField' ],
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
			$out->addWikiMsg( 'reports-number-of-articles-updated', $this->getCount( $data ) );
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
		$dbr                 = $this->getDB();
		$actorsToIgnoreQuery = $this->getSubqueryIgnoredActors();
		$this->tables        = [ 'revision', 'page' ];
		$this->fields        = [
			'title' => 'page_title',
			'last_revision' => 'MAX(revision.rev_timestamp)',
		];

		self::limitByCategory( $data );
		self::limitByDates( $data );

		$this->conds[ 'page_namespace' ] = 0;
		$this->conds[ 'page.page_is_redirect' ] = 0;
		// We want updates, not creation of articles
		$this->conds[] = 'revision.rev_parent_id != 0';

		// Make sure this is not a replacetext edit - because we want to exclude mass edits here
		// Try to make this apply to multiple language (he, ar, en) by exploding the edit summary
		$msgText = $this->msg( 'replacetext_editsummary' )->text();
		$msgText = strtok( $msgText, '-–' );
		$commentStore = MediaWikiServices::getInstance()->getCommentStore();
		$commentQuery = $commentStore->getJoin( 'rev_comment' );
		$this->conds[] = 'comment_text NOT' . $dbr->buildLike( $msgText, $dbr->anyString() );

		// Ignore edits by certain users
		$actorQuery = ActorMigration::newMigration()->getJoin( 'rev_user' );
		$this->conds[] = 'revactor_actor NOT IN (' . $actorsToIgnoreQuery . ')';

		$this->joinConds['page'] = [ 'LEFT JOIN', 'rev_page=page_id' ];
		$this->options[ 'GROUP BY'] = 'title';

		$result = $dbr->select(
			$this->tables + $commentQuery['tables'] + $actorQuery['tables'],
			$this->fields + $commentQuery['fields'] + $actorQuery['fields'],
			$this->conds,
			__METHOD__,
			$this->options,
			$this->joinConds + $commentQuery['joins'] + $actorQuery['joins']
		);

		return $result->numRows();
	}

	/**
	 * @return string SQL subquery
	 */
	protected function getSubqueryIgnoredActors() {
		$dbr = wfGetDB( DB_REPLICA );

		$nonHumanGroups = [ 'automaton' ];

		return $dbr->selectSQLText(
			[ 'user_groups', 'actor' ],
			'actor_id',
			[ 'ug_group' => $nonHumanGroups ],
			__METHOD__,
			[ 'GROUP BY' => [ 'actor_id' ] ],
			[ 'actor' => [ 'JOIN', 'actor_user = ug_user' ] ]
		);
	}

	/**
	 * @param array $data
	 */
	protected function limitByDates( $data ) {
		$db = $this->getDB();
		$fromDate = $data[ 'from' ];
		$toDate = $data[ 'to' ];

		if ( $fromDate ) {
			$this->conds[] = 'rev_timestamp >= ' .
					   $db->addQuotes( $db->timestamp( new DateTime( $fromDate ) ) );
		}
		if ( $toDate ) {
			// Add 1 day, so we check for "any date before tomorrow"
			$this->conds[] = 'rev_timestamp < ' .
					   $db->addQuotes(
						   $db->timestamp( new DateTime( $toDate . ' +1 day' ) )
					   );
		}
	}

	/**
	 * @param array $data
	 */
	protected function limitByCategory( $data ) {
		if ( empty( $data[ 'category' ] ) ) {
			return;
		}
		$titleParser = MediaWikiServices::getInstance()->getTitleParser();
		$categoryTitleValue = $titleParser->parseTitle( $data['category'], NS_CATEGORY );
		$this->tables[] = 'categorylinks';
		$this->joinConds['categorylinks'] = [ 'INNER JOIN',
			'rev_page=cl_from AND cl_to="' . $categoryTitleValue->getDBkey() . '"'
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
		return $x !== null && $x !== false & $x !== '';
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
