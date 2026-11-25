<?php
/**
 * Special page to display pages with their real last human update dates
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup SpecialPage
 */

namespace MediaWiki\Extension\RealLastUpdate;

use ExtensionRegistry;
use HTMLForm;
use MediaWiki\Html\Html;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\Title\Title;
use SpecialPage;

class SpecialRealLastUpdate extends SpecialPage {
	/** @var RealLastUpdatePager|null */
	protected ?RealLastUpdatePager $pager = null;

	/** @var array */
	protected array $formFilter = [];

	private NamespaceInfo $namespaceInfo;

	/**
	 * @param NamespaceInfo $namespaceInfo
	 */
	public function __construct( NamespaceInfo $namespaceInfo ) {
		parent::__construct( 'RealLastUpdate', 'edit' );
		$this->namespaceInfo = $namespaceInfo;
	}

	/**
	 * @param string|null $subPage
	 */
	public function execute( $subPage ) {
		$this->setHeaders();
		// The 'edit' restriction passed to the constructor is only declared
		// there; it takes effect here, before any form or query is built.
		$this->checkPermissions();
		$this->outputHeader();
		$out = $this->getOutput();
		$out->addModuleStyles( 'mediawiki.special' );
		$out->addModules( 'ext.realLastUpdate.special' );

		// Parse request parameters. No namespace means the main namespace,
		// and an empty one means every namespace the viewer can read.
		$request = $this->getRequest();
		$namespace = $request->getVal( 'namespace' );
		if ( $namespace === null ) {
			$namespace = NS_MAIN;
		} elseif ( $namespace === '' ) {
			$namespace = null;
		} else {
			$namespace = (int)$namespace;
		}
		$this->formFilter['namespace'] = $namespace;
		$this->formFilter['articletype'] = $request->getVal( 'articletype' );
		$this->formFilter['contentarea'] = $request->getVal( 'contentarea' );

		// The report lists titles and their edit times, so it covers only
		// namespaces the viewer may read; having 'edit' does not imply that.
		$readable = $this->getReadableNamespaces();

		// Show the filter form
		$this->showFilterForm( $readable );

		$namespaces = $namespace === null
			? $readable
			: array_values( array_intersect( [ $namespace ], $readable ) );
		if ( !$namespaces ) {
			$out->addHTML( Html::errorBox(
				$this->msg( 'reallastupdate-namespace-unreadable' )->escaped()
			) );
			return;
		}
		$this->formFilter['namespaces'] = $namespaces;

		// Show the pager
		$this->pager = new RealLastUpdatePager( $this, $this->formFilter );
		$out->addParserOutputContent( $this->pager->getFullOutput() );
	}

	/**
	 * The namespaces whose pages the viewer may read
	 *
	 * Read access is decided per title (Lockdown, for one, restricts whole
	 * namespaces through the per-title permission hook), so one title in
	 * each namespace stands in for the namespace.
	 *
	 * @return int[]
	 */
	private function getReadableNamespaces(): array {
		$authority = $this->getAuthority();
		$readable = [];
		foreach ( $this->namespaceInfo->getValidNamespaces() as $namespace ) {
			$probe = Title::makeTitle( $namespace, 'RealLastUpdate' );
			if ( $authority->authorizeRead( 'read', $probe ) ) {
				$readable[] = $namespace;
			}
		}
		return $readable;
	}

	/**
	 * Show the filter form with namespace, article type and content area filters
	 *
	 * @param int[] $readable The namespaces the viewer may read; only these are offered
	 */
	private function showFilterForm( array $readable ) {
		$namespaceOptions = Html::namespaceSelectorOptions( [
			'all' => '',
			'exclude' => array_diff( $this->namespaceInfo->getValidNamespaces(), $readable ),
		] );
		$formDescriptor = [
			'namespace' => [
				'type' => 'select',
				'name' => 'namespace',
				'id' => 'namespace',
				'label-message' => 'reallastupdate-namespace',
				'options' => array_flip( $namespaceOptions ),
				'default' => $this->formFilter['namespace'] ?? '',
			]
		];

		// Add article type filter if ArticleType extension is loaded
		if ( ExtensionRegistry::getInstance()->isLoaded( 'ArticleType' ) ) {
			$articleTypes = [];
			$validTypes = \MediaWiki\Extension\ArticleType\ArticleType::getValidArticleTypes();

			// Create a dropdown with article types
			foreach ( $validTypes as $type ) {
				$articleTypes[$type] = $type;
			}

			if ( !empty( $articleTypes ) ) {
				$formDescriptor['articletype'] = [
					'type' => 'select',
					'name' => 'articletype',
					'id' => 'articletype',
					'label-message' => 'reallastupdate-articletype',
					'options' => array_merge( [ $this->msg( 'reallastupdate-all' )->text() => '' ], $articleTypes ),
					'default' => $this->formFilter['articletype'] ?? '',
				];
			}
		}

		// Add content area filter if ArticleContentArea extension is loaded
		if ( ExtensionRegistry::getInstance()->isLoaded( 'ArticleContentArea' ) ) {
			$contentAreas = [];
			$validAreas = \MediaWiki\Extension\ArticleContentArea\ArticleContentArea::getAssignedContentAreas();

			// Create a dropdown with content areas
			foreach ( $validAreas as $key => $label ) {
				$contentAreas[$label] = $key;
			}

			if ( !empty( $contentAreas ) ) {
				$formDescriptor['contentarea'] = [
					'type' => 'select',
					'name' => 'contentarea',
					'id' => 'contentarea',
					'label-message' => 'reallastupdate-contentarea',
					'options' => array_merge( [ $this->msg( 'reallastupdate-all' )->text() => '' ], $contentAreas ),
					'default' => $this->formFilter['contentarea'] ?? '',
				];
			}
		}

		$form = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$form->setMethod( 'get' )
			->setSubmitTextMsg( 'reallastupdate-filter' )
			->setWrapperLegendMsg( 'reallastupdate-filter-legend' )
			->prepareForm()
			->displayForm( false );
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'pages';
	}
}
