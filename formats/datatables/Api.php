<?php
/**
 * SRF DataTables and SMWAPI.
 *
 * @see http://datatables.net/
 *
 * @license GPL-2.0-or-later
 * @author thomas-topway-it for KM-A
 * @credits Stephan Gambke (SRFSlideShowApi)
 */

namespace SRF\DataTables;

use ApiBase;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use ParamProcessor\ParamDefinitionFactory;
use SMW\DataValueFactory;
use SMW\DataValues\PropertyChainValue;
use SMW\Query\PrintRequest;
use SMW\Services\ServicesFactory;
use SMWQuery;
use SMWQueryProcessor;
use SpecialVersion;
use SRF\DataTables;

class Api extends ApiBase {

	/**
	 * Evaluates the parameters, performs the query, and sets up the result.
	 *
	 * The result data is stored in the ApiResult object available through getResult().
	 */
	public function execute() {
		// get request parameters
		$requestParams = $this->extractRequestParams();

		$data = json_decode( $requestParams['data'], true );

		// @see https://datatables.net/reference/option/ajax
		$datatableData = $data['datatableData'];
		$settings = $data['settings'];

		if ( empty( $datatableData['start'] ) ) {
			$datatableData['start'] = 0;
		}

		if ( empty( $datatableData['draw'] ) ) {
			$datatableData['draw'] = 0;
		}

		$printer = new Datatables( 'datatables', true );

		// get defaults of parameters for the 'datatable' result format as array of ParamDefinition
		$paramDefinitions = ParamDefinitionFactory::newDefault()->newDefinitionsFromArrays( $printer->getParamDefinitions( [] ) );

		// transform into normal key-value array
		$parameters = [];

		foreach ( $paramDefinitions as $def ) {
			$parameters[$def->getName()] = $def->getDefault();
		}

		$printoutsRaw = $data['printouts'];

		// add/set specific parameters for this call
		$parameters = array_merge(
			$parameters,
			[
				// *** important !!
				'format' => 'datatables',
				'apicall' => 'apicall',

				// @TODO limit taking into account PreloaData
				'limit' => max( $datatableData['length'], $settings['limit'] ),
				'offset' => $datatableData['start'],

				'sort' => implode( ',', array_map( static function ( $value ) use( $datatableData ) {
					return $datatableData['columns'][$value['column']]['name'];
				}, $datatableData['order'] ) ),

				'order' => implode( ',', array_map( static function ( $value ) {
					return $value['dir'];
				}, $datatableData['order'] ) )

			]
		);

		// A bit of a hack since the parser isn't run, avoids [[SMW::off]]/[[SMW::on]]
		$parameters['import-annotation'] = 'true';

		// transform query parameters into format suitable for SMWQueryProcessor
		$queryParams = SMWQueryProcessor::getProcessedParams( $parameters, [] );

		// @TODO use printrequests for printouts as well
		// printrequests seems to lack of the "parameters"
		// parameter only

		$hasMainlabel = array_key_exists( 'mainlabel', $parameters );

		// build array of printouts
		$printouts = [];
		$dataValueFactory = DataValueFactory::getInstance();
		foreach ( $printoutsRaw as $printoutData ) {

			// create property from property key
			if ( $printoutData[0] === PrintRequest::PRINT_PROP ) {
				$data_ = $dataValueFactory->newPropertyValueByLabel( $printoutData[1] );
			} elseif ( $printoutData[0] === PrintRequest::PRINT_CHAIN ) {
				$data_ = $dataValueFactory->newDataValueByType(
					PropertyChainValue::TYPE_ID
				);
				$data_->setUserValue( $printoutData[1] );
			} else {
				$data_ = null;
				if ( $hasMainlabel && trim( $parameters['mainlabel'] ) === '-' ) {
					continue;
				}
				// match something like |?=abc |+template=mytemplate
			}

			// create printrequest from request mode, label, property name, output format, parameters
			$printouts[] = new PrintRequest(
				// mode
				$printoutData[0],
				// (canonical) label
				$printoutData[1],
				// property name
				$data_,
				// output format
				$printoutData[3],
				// parameters
				$printoutData[4]
			);

		}

		// SMWQueryProcessor::addThisPrintout( $printouts, $parameters );

		$printrequests = $data['printrequests'];
		$columnDefs = $data['columnDefs'];

		$getColumnAttribute = static function ( $label, $attr ) use( $columnDefs ) {
			foreach ( $columnDefs as $value ) {
				if ( $value['name'] === $label && array_key_exists( $attr, $value ) ) {
					return $value[$attr];
				}
			}
			return null;
		};

		// filter the query
		$allowedTypes = [ '_wpg', '_txt', '_cod', '_uri', '_keyw' ];

		$queryDisjunction = [];
		if ( !empty( $datatableData['search']['value'] ) ) {
			foreach ( $printoutsRaw as $key => $value ) {
				$printrequest = $printrequests[$key];

				if ( !in_array( $printrequest['typeid'], $allowedTypes ) ) {
					continue;
				}

				// $value['key'] === '' is the mainlabel, is this always reliable ?
				$label = ( $printrequest['key'] !== '' ? $value[1] : '' );
				$searchable = $getColumnAttribute( $label, 'searchable' );

				if ( $searchable === null || $searchable === true ) {
					$queryDisjunction[] = '[[' . ( $label !== '' ? $label . '::' : '' ) . '~*' . $datatableData['search']['value'] . '*]]';
				}
			}
		}

		$queryConjunction = [];
		foreach ( $printoutsRaw as $key => $value ) {
			if ( !empty( $datatableData['searchPanes'][$key] ) ) {
				$printrequest = $printrequests[$key];
				$label = ( $printrequest['key'] !== '' ? $value[1] : '' );
				// @TODO consider combiner
				// https://www.semantic-mediawiki.org/wiki/Help:Unions_of_results#User_manual
				$queryConjunction[] = '[[' . ( $label !== '' ? $label . '::' : '' ) . implode( '||', $datatableData['searchPanes'][$key] ) . ']]';
			}
		}

		// @see https://datatables.net/extensions/searchbuilder/customConditions.html
		// @see https://datatables.net/reference/option/searchBuilder.depthLimit
		if ( !empty( $datatableData['searchBuilder'] ) ) {
			$searchBuilder = [];
			foreach ( $datatableData['searchBuilder']['criteria'] as $criteria ) {
				foreach ( $printoutsRaw as $key => $value ) {
					// @FIXME $label isn't simply $value[1] ?
					$printrequest = $printrequests[$key];
					$label = ( $printrequest['key'] !== '' ? $value[1] : '' );

					// SearchBuilder names a column by its title, which is the
					// printout label, while the query condition needs the
					// property name
					if ( ( $printrequest['label'] ?? $label ) === $criteria['data'] ) {

						// nested condition, skip for now
						if ( !array_key_exists( 'condition', $criteria ) ) {
							continue;
						}
						$v = implode( $criteria['value'] );
						$str = ( $label !== '' ? "$label::" : '' );

						if ( $label !== '' && ( $printrequest['typeid'] ?? '' ) === '_dat' ) {
							$dateCondition = self::dateCondition( $label, $criteria );

							if ( $dateCondition !== null ) {
								$searchBuilder[] = $dateCondition;
								continue;
							}
						}

						switch ( $criteria['condition'] ) {
							case '=':
								$searchBuilder[] = "[[{$str}{$v}]]";
								break;
							case '!=':
								$searchBuilder[] = "[[{$str}!~$v]]";
								break;
							case 'starts':
								$searchBuilder[] = "[[{$str}~$v*]]";
								break;
							case '!starts':
								$searchBuilder[] = "[[{$str}!~$v*]]";
								break;
							case 'contains':
								$searchBuilder[] = "[[{$str}~*$v*]]";
								break;
							case '!contains':
								$searchBuilder[] = "[[{$str}!~*$v*]]";
								break;
							case 'ends':
								$searchBuilder[] = "[[{$str}~*$v]]";
								break;
							case '!ends':
								$searchBuilder[] = "[[$str}!~*$v]]";
								break;
							// case 'null':
							// 	break;
							case '!null':
								if ( $label ) {
									$searchBuilder[] = "[[$label::+]]";
								}
								break;

						}
					}
				}
			}
			if ( $datatableData['searchBuilder']['logic'] === 'AND' ) {
				$queryConjunction = array_merge( $queryConjunction, $searchBuilder );
			} elseif ( $datatableData['searchBuilder']['logic'] === 'OR' ) {
				$queryDisjunction = array_merge( $queryDisjunction, $searchBuilder );
			}
		}

		global $smwgQMaxSize;

		if ( !count( $queryDisjunction ) ) {
			$queryDisjunction = [ '' ];
		}

		$query = $data['queryString'] . implode( '', $queryConjunction );

		$conditions = array_map( static function ( $value ) use ( $query ) {
			return $query . $value;
		}, $queryDisjunction );

		// @TODO get query size as in class Conjunction
		$smwgQMaxSize = 128;

		$queryStr =	implode( 'OR', $conditions );

		$log['queryStr '] = $queryStr;

		$query = SMWQueryProcessor::createQuery(
			$queryStr,
			$queryParams,
			SMWQueryProcessor::INLINE_QUERY,
			'',
			$printouts
		);

		// $size = $query->getDescription()->getSize();

		// $smwgQMaxSize = max( $smwgQMaxSize, $size );
		// trigger_error('smwgQMaxSize ' . $smwgQMaxSize);

		$applicationFactory = ServicesFactory::getInstance();
		$queryEngine = $applicationFactory->getStore();
		$results = $queryEngine->getQueryResult( $query );

		// or SMW_OUTPUT_RAW
		$res = $printer->getResult( $results, $queryParams, SMW_OUTPUT_FILE );

		global $smwgQMaxLimit, $smwgQMaxInlineLimit;

		// get count
		if ( !empty( $datatableData['search']['value'] ) || count( $queryConjunction ) ) {
			$queryDescription = $query->getDescription();
			$queryCount = new SMWQuery( $queryDescription );
			$queryCount->setLimit( min( $smwgQMaxLimit, $smwgQMaxInlineLimit ) );
			$queryCount->setQuerySource( SMWQuery::MODE_COUNT );
			$queryResult = $queryEngine->getQueryResult( $queryCount );
			$count = $queryResult->getCount();

		} else {
			$count = $settings['count'];
		}

		// @see https://datatables.net/extensions/scroller/examples/initialisation/server-side_processing.html
		$ret = [
			'draw' => $datatableData['draw'],
			'data' => $res,
			'recordsTotal' => $settings['count'],
			'recordsFiltered' => $count,
			'cacheKey' => $data['cacheKey'],
			'datalength' => $datatableData['length'],
			'start' => $datatableData['start']
		];

		if ( $settings['displayLog'] ) {
			$ret['log'] = $log;
		}

		$this->getResult()->addValue( null, "datatables-json", $ret );
	}

	/**
	 * Translates a SearchBuilder criteria on a date column into a query
	 * condition. In the browser the criteria is compared against the filter
	 * key built by SRF\DataTables::getCellContent, which is the ISO 8601 day
	 * or, for a column using the moment conditions, the ISO 8601 second. The
	 * query reproduces that by bounding the range to the same granularity.
	 *
	 * Only the strict comparators are used, so that the result does not depend
	 * on $smwgStrictComparators.
	 *
	 * @param string $label
	 * @param array $criteria
	 * @return string|null null when the criteria carries no usable date
	 */
	private static function dateCondition( string $label, array $criteria ): ?string {
		$bounds = [];

		foreach ( $criteria['value'] ?? [] as $value ) {
			$bound = self::dateBounds( (string)$value );

			if ( $bound === null ) {
				return null;
			}

			$bounds[] = $bound;
		}

		$single = $bounds[0] ?? null;

		switch ( $criteria['condition'] ) {
			case '=':
				return $single !== null
					? "[[$label::>>{$single['before']}]][[$label::<<{$single['after']}]]"
					: null;

			case '!=':
				return $single !== null
					? "[[$label::<<{$single['start']}||>>{$single['end']}]]"
					: null;

			case '<':
				return $single !== null ? "[[$label::<<{$single['start']}]]" : null;

			case '>':
				return $single !== null ? "[[$label::>>{$single['end']}]]" : null;

			case 'between':
			case '!between':
				if ( count( $bounds ) < 2 ) {
					return null;
				}

				// SearchBuilder accepts the two values in either order
				[ $from, $to ] = $bounds[0]['start'] <= $bounds[1]['start']
					? [ $bounds[0], $bounds[1] ]
					: [ $bounds[1], $bounds[0] ];

				return $criteria['condition'] === 'between'
					? "[[$label::>>{$from['before']}]][[$label::<<{$to['after']}]]"
					: "[[$label::<<{$from['start']}||>>{$to['end']}]]";
		}

		return null;
	}

	/**
	 * First and last instant of the day or second a filter key stands for,
	 * plus the instant immediately outside either end.
	 *
	 * @param string $value
	 * @return array|null null when the value is not a filter key of a date column
	 */
	private static function dateBounds( string $value ): ?array {
		$value = trim( $value );
		$utc = new DateTimeZone( 'UTC' );

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$start = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', "$value 00:00:00", $utc );
			$length = 'P1D';
		} elseif ( preg_match( '/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})$/', $value, $matches ) ) {
			$start = DateTimeImmutable::createFromFormat(
				'Y-m-d H:i:s', $matches[1] . ' ' . $matches[2], $utc
			);
			$length = 'PT1S';
		} else {
			return null;
		}

		if ( $start === false ) {
			return null;
		}

		$second = new DateInterval( 'PT1S' );
		$end = $start->add( new DateInterval( $length ) )->sub( $second );
		$format = 'Y-m-d\TH:i:s';

		return [
			'before' => $start->sub( $second )->format( $format ),
			'start' => $start->format( $format ),
			'end' => $end->format( $format ),
			'after' => $end->add( $second )->format( $format )
		];
	}

	/**
	 * Returns the description string for this module
	 *
	 * @return mixed string or array of strings
	 */
	protected function getDescription() {
		return [
			'API module used by the Datatables (v2) result printer to retrieve formatted results.',
			'This module should not be called directly.'
		];
	}

	/**
	 * Returns usage examples for this module. Return false if no examples are available.
	 *
	 * @return bool|string|array
	 */
	protected function getExamples() {
		return false;
	}

	public function getHelpUrls() {
		return 'http://semantic-mediawiki.org/wiki/Help:Datatables_format';
	}

	/**
	 * Returns an array of allowed parameters
	 *
	 * @return array|bool
	 */
	protected function getAllowedParams() {
		return [
			'data' => [
				ApiBase::PARAM_TYPE => 'string',
				ApiBase::PARAM_REQUIRED => true,
			]
		];
	}

	/**
	 * Returns a string that identifies the version of the extending class.
	 * Typically includes the class name, the svn revision, timestamp, and
	 * last author. Usually done with SVN's Id keyword
	 *
	 * @return string
	 */
	public function getVersion() {
		global $srfgIP;
		$gitSha1 = SpecialVersion::getGitHeadSha1( $srfgIP );
		return __CLASS__ . '-' . SRF_VERSION . ( $gitSha1 !== false ) ? ' (' . substr( $gitSha1, 0, 7 ) . ')' : '';
	}

}
