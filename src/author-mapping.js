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
				<h2>Import WordPress</h2>
				<div>To make it easier for you to edit and save the imported content, you may want to reassign the author of the imported item to an existing user of this site. For example, you may want to import all the entries as admins entries.</div>
				<div>If a new user is created by WordPress, a new password will be randomly generated and the new user’s role will be set as subscriber. Manually changing the new user’s details will be necessary.</div>

				<h3>Assign Authors</h3>

				...

				<h3>Import Attachments</h3>

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
