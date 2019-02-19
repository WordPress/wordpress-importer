/**
 * External dependencies
 */
import React, { PureComponent } from 'react';
import { withSelect } from '@wordpress/data';
import { SelectControl } from '@wordpress/components';
import { Link } from 'react-router-dom';

class AuthorMapping extends PureComponent {
	render() {
		const { importAuthors, siteAuthors } = this.props;
		console.log( importAuthors, siteAuthors );
		return (
			<div>
				<SelectControl label="Author" options={
					siteAuthors.map( author => {
						return {
							label: author.name,
							value: author.id,
						};
					} )
				} />
				<Link to='/complete'>FINISH!!!</Link>
			</div>
		);
	}
}

export default withSelect( ( select ) => {
	return {
		siteAuthors: select( 'core' ).getAuthors(),
		importAuthors: select( 'wordpress-importer' ).getImportAuthors(),
	};
} )( AuthorMapping );
