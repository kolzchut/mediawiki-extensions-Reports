<?php

namespace MediaWiki\Extension\KolzchutReports;

use DateTime;
use ErrorPageError;
use HTMLForm;
use MalformedTitleException;
use MediaWiki\CommentStore\CommentStore;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\Title\TitleParser;
use Status;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

class SpecialArticlesUpdatesReport extends FormSpecialPage {

	public function __construct(
	private readonly IConnectionProvider $dbProvider,
	private readonly CommentStore $commentStore,
	private readonly TitleParser $titleParser,
	) {
		parent::__construct( 'ArticlesUpdatesReport', 'viewreports' );
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
		$this->getOutput()->addModuleStyles( 'ext.wikirights.reports' );
		$form = $this->getForm();
		$form->prepareForm();
		$result = $form->tryAuthorizedSubmit();
		$form->displayForm( $result );
	}

	protected function alterForm( HTMLForm $form ) {
		$form->setWrapperLegendMsg( 'reports-form-legend' );
		$form->setCollapsibleOptions( $this->getRequest()->wasPosted() );
	}

	protected function getFormFields(): array {
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
	],
	'ignoreusers' => [
	'class' => 'HTMLUsersMultiselectField',
	'name' => 'ignoreusers',
	'label-message' => 'reports-field-ignoreusers',
	'help-message' => 'reports-field-ignoreusers-help',
	'exists' => false,
	'ipallowed' => false,
	'required' => false,
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
	 * @return array|bool|Status|string
	 */
	public function onSubmit( array $data ) {
		$status = $this->requireAtLeastOneParameter( $data, 'from', 'to' );
		if ( !$status->isGood() ) {
			return $status;
		}
		$out = $this->getOutput();
		$count = $this->getCount( $data );
		$out->addHTML( '<div class="reports-result-box">' );
		$out->addWikiMsg( 'reports-number-of-articles-updated', '<strong>' . $count . '</strong>' );
		$out->addHTML( '</div>' );
		if ( !empty( $data['debug'] ) ) {
			$this->outputDebugSection( $data );
		}
		return true;
	}

	private function outputDebugSection( array $data ): void {
		$out = $this->getOutput();
		$allEditors = $this->getDistinctEditors( $data, false );
		$botNames = $this->getDB()->newSelectQueryBuilder()
		->table( 'user_groups' )
		->join( 'actor', null, 'actor_user = ug_user' )
		->field( 'actor_name' )
		->where( [ 'ug_group' => [ 'automaton' ] ] )
		->groupBy( 'actor_name' )
		->caller( __METHOD__ )
		->fetchFieldValues();
		foreach ( $this->parseIgnoreUsers( $data ) as $username ) {
			$botNames[] = $username;
		}
		$botNamesSet = array_flip( $botNames );
		$humans = [];
		$bots = [];
		foreach ( $allEditors as $editor ) {
			if ( isset( $botNamesSet[$editor] ) ) {
				$bots[] = $editor;
			} else {
				$humans[] = $editor;
			}
		}
		$out->addHTML( '<div class="reports-debug-section">' );
		$out->addHTML( '<h3>' . $this->msg( 'reports-debug-editor-details' )->escaped() . '</h3>' );
		$out->addHTML( '<p><strong>' . $this->msg( 'reports-distinct-users' )->escaped() . ':</strong> ' .
		$this->msg( 'reports-people-and-bots' )
		->numParams( count( $humans ), count( $bots ) )
		->escaped() . '</p>' );
		$out->addHTML( '<p><strong>' . $this->msg( 'reports-names' )->escaped() . ':</strong></p>' );
		$out->addHTML( '<ol class="reports-editor-list">' );
		foreach ( $humans as $editor ) {
			$out->addHTML( '<li>' . htmlspecialchars( $editor ) . '</li>' );
		}
		foreach ( $bots as $editor ) {
			$out->addHTML( '<li><del>' . htmlspecialchars( $editor ) . '</del></li>' );
		}
		$out->addHTML( '</ol></div>' );
	}

	private function getDB(): IReadableDatabase {
		return $this->dbProvider->getReplicaDatabase();
	}

	protected function getCount( array $data ): int {
		$dbr = $this->getDB();
		$additionalUsers = $this->parseIgnoreUsers( $data );
		$msgText = strtok( $this->msg( 'replacetext_editsummary' )->text(), '-–' );

		$queryBuilder = $dbr->newSelectQueryBuilder()
		->select( [ 'count' => 'COUNT(DISTINCT rev_page)' ] )
		->from( 'revision' )
		->leftJoin( 'page', null, 'rev_page=page_id' )
		->queryInfo( $this->commentStore->getJoin( 'rev_comment' ) )
		->where( [
	'page_namespace' => NS_MAIN,
	'page.page_is_redirect' => 0,
		] )
	// We want updates, not creation of articles
		->where( 'revision.rev_parent_id != 0' )
	// Exclude ReplaceText mass edits
		->where( 'comment_text NOT' . $dbr->buildLike( $msgText, $dbr->anyString() ) )
	// Ignore edits by bots and specified users
		->where( 'rev_actor NOT IN (' . $this->getSubqueryIgnoredActors( $additionalUsers ) . ')' )
		->caller( __METHOD__ );

		$this->applyDateLimits( $queryBuilder, $dbr, $data );
		$this->applyCategory( $queryBuilder, $data );

		$row = $queryBuilder->fetchRow();
		return (int)( $row ? $row->count : 0 );
	}

	/**
	 * Get list of distinct editor names who made updates
	 *
	 * @param array $data
	 * @param bool $excludeBots Whether to exclude bot/ignored users
	 * @return string[] Array of editor names
	 */
	protected function getDistinctEditors( array $data, bool $excludeBots = true ): array {
		$dbr = $this->getDB();
		$additionalUsers = $this->parseIgnoreUsers( $data );
		$msgText = strtok( $this->msg( 'replacetext_editsummary' )->text(), '-–' );

		$queryBuilder = $dbr->newSelectQueryBuilder()
		->select( [ 'user_name' => 'actor_name' ] )
		->from( 'revision' )
		->leftJoin( 'page', null, 'rev_page=page_id' )
		->join( 'actor', null, 'actor_id = rev_actor' )
		->queryInfo( $this->commentStore->getJoin( 'rev_comment' ) )
		->where( [
	'page_namespace' => NS_MAIN,
	'page.page_is_redirect' => 0,
		] )
		->where( 'revision.rev_parent_id != 0' )
		->where( 'comment_text NOT' . $dbr->buildLike( $msgText, $dbr->anyString() ) )
		->groupBy( 'actor_name' )
		->orderBy( 'actor_name' )
		->caller( __METHOD__ );

		if ( $excludeBots ) {
			$queryBuilder->where(
		'rev_actor NOT IN (' . $this->getSubqueryIgnoredActors( $additionalUsers ) . ')'
			);
		}

		$this->applyDateLimits( $queryBuilder, $dbr, $data );
		$this->applyCategory( $queryBuilder, $data );

		$editors = [];
		foreach ( $queryBuilder->fetchResultSet() as $row ) {
			$editors[] = $row->user_name;
		}
		return $editors;
	}

	/**
	 * @param string[] $additionalUsers Additional usernames to ignore beyond automaton group
	 * @return string SQL subquery returning actor_id values to ignore
	 */
	protected function getSubqueryIgnoredActors( array $additionalUsers = [] ): string {
		$dbr = $this->getDB();
		$groupCond = $dbr->expr( 'ug_group', '=', [ 'automaton' ] );
		$whereCond = $additionalUsers !== []
			? $dbr->orExpr( [ $groupCond, $dbr->expr( 'actor_name', '=', $additionalUsers ) ] )
			: $groupCond;

		$qb = $dbr->newSelectQueryBuilder()
		->table( 'user_groups' )
		->leftJoin( 'actor', null, 'actor_user = ug_user' )
		->field( 'actor_id' )
		->where( $whereCond )
		->groupBy( 'actor_id' )
		->caller( __METHOD__ );


		return $qb->getSQL();
	}

	/**
	 * @param array $data
	 * @return string[]
	 */
	private function parseIgnoreUsers( array $data ): array {
		if ( empty( $data['ignoreusers'] ) ) {
			return [];
		}
		return array_values( array_filter(
		array_map( 'trim', explode( "\n", $data['ignoreusers'] ) ),
		static fn ( $u ) => $u !== ''
) );
	}

	private function applyDateLimits( SelectQueryBuilder $qb, IReadableDatabase $dbr, array $data ): void {
		if ( !empty( $data['from'] ) ) {
			$qb->where(
		'rev_timestamp >= ' . $dbr->addQuotes( $dbr->timestamp( new DateTime( $data['from'] ) ) )
			);
		}
		if ( !empty( $data['to'] ) ) {
		// Add 1 day to check for "any date before tomorrow"
			$qb->where(
		'rev_timestamp < ' . $dbr->addQuotes(
			$dbr->timestamp( new DateTime( $data['to'] . ' +1 day' ) )
			)
			);
		}
	}

	private function applyCategory( SelectQueryBuilder $qb, array $data ): void {
		if ( empty( $data['category'] ) ) {
			return;
		}
		try {
			$categoryTitleValue = $this->titleParser->parseTitle( $data['category'], NS_CATEGORY );
		} catch ( MalformedTitleException $e ) {
			throw new ErrorPageError( $e->getTitleText(), $e->getMessage() );
		}
		$qb->join( 'categorylinks', null, [
	'rev_page = cl_from',
	'cl_to' => $categoryTitleValue->getDBkey()
		] );
	}

	/**
	 * Copied from ApiBase::requireAtLeastOneParameter(), but also makes sure no '' values are given
	 *
	 * @param array $params User provided set of parameters
	 * @param string ...$required Names of parameters of which at least one must be set
	 * @return Status
	 */
	public function requireAtLeastOneParameter( array $params, string ...$required ): Status {
		$intersection = array_intersect(
		array_keys( array_filter( $params, [ $this, 'parameterNotEmpty' ] ) ),
		$required
		);

		if ( count( $intersection ) === 0 ) {
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
	 * @param string|int|null $x
	 * @return bool
	 */
	private function parameterNotEmpty( $x ): bool {
		return $x !== null && $x !== false && $x !== '';
	}

	public function requiresWrite(): bool {
		return false;
	}

	protected function getDisplayFormat(): string {
		return 'ooui';
	}

	protected function getGroupName(): string {
		return 'changes';
	}
}
