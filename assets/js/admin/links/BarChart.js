import { __, sprintf } from '@wordpress/i18n';

import { formatNumber } from '../utils';

const HEIGHT = 160;

/**
 * Daily clicks as a dependency-free SVG bar chart. Each bar has a title, so
 * values show on hover and are read by screen readers.
 *
 * @param {Object} props
 * @param {Array}  props.data Days with day, clicks and uniques.
 */
export default function BarChart( { data } ) {
	const max = Math.max( 1, ...data.map( ( day ) => day.clicks ) );
	const width = 100 / data.length;

	return (
		<figure className="fus-chart">
			<svg
				viewBox={ `0 0 100 ${ HEIGHT }` }
				preserveAspectRatio="none"
				role="img"
				aria-label={ __( 'Clicks per day', 'fast-url-shortener' ) }
			>
				{ data.map( ( day, index ) => {
					const height = ( day.clicks / max ) * ( HEIGHT - 4 );

					return (
						<rect
							key={ day.day }
							x={ index * width + width * 0.1 }
							y={ HEIGHT - height }
							width={ width * 0.8 }
							height={ Math.max( height, day.clicks ? 1 : 0 ) }
						>
							<title>
								{ sprintf(
									/* translators: 1: date, 2: clicks, 3: unique visitors. */
									__(
										'%1$s: %2$s clicks, %3$s unique',
										'fast-url-shortener'
									),
									day.day,
									formatNumber( day.clicks ),
									formatNumber( day.uniques )
								) }
							</title>
						</rect>
					);
				} ) }
			</svg>
			<figcaption>
				<span>{ data[ 0 ]?.day }</span>
				<span>
					{ sprintf(
						/* translators: %s: highest daily click count. */
						__( 'Peak: %s clicks/day', 'fast-url-shortener' ),
						formatNumber(
							max === 1 && ! data.some( ( d ) => d.clicks )
								? 0
								: max
						)
					) }
				</span>
				<span>{ data[ data.length - 1 ]?.day }</span>
			</figcaption>
		</figure>
	);
}
