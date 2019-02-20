/**
 * External dependencies
 */
import React, { PureComponent } from 'react';
import { withSelect } from '@wordpress/data';
import { SelectControl } from '@wordpress/components';
import { Link } from 'react-router-dom';
import apiFetch from '@wordpress/api-fetch';

class AuthorMapping extends PureComponent {

	renderImportAuthor( importAuthor ) {
		const { siteAuthors } = this.props;

		const selectOptions = [
			{ label: `Create a new user: ${importAuthor.author_login}`, value: importAuthor.author_login },
			...siteAuthors.map( author => {
				return {
					label: `Existing user: ${author.name}`,
					value: author.id,
				};
			} )
		]

		return (
			<li key={ `author_${importAuthor.author_login}` }>
				Import author: { importAuthor.author_display_name } ({ importAuthor.author_login })
				<SelectControl label="Map this author's posts to:" options={ selectOptions } />
			</li>
		);
	}

	render() {
		window.apiFetch = apiFetch;

		const { importAuthors } = this.props;

		return (
			<div>
				<h2>Import WordPress</h2>
				<div>To make it easier for you to edit and save the imported content, you may want to reassign the author of the imported item to an existing user of this site. For example, you may want to import all the entries as admins entries.</div>
				<div>If a new user is created by WordPress, a new password will be randomly generated and the new user’s role will be set as subscriber. Manually changing the new user’s details will be necessary.</div>

				<h3>Assign Authors</h3>

				<ol>
					{ importAuthors.map( importAuthor => this.renderImportAuthor( importAuthor ) ) }
				</ol>
				

				<h3>Import Attachments</h3>

				<label><input type="checkbox" /> Download and import file attachments</label>
				
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
