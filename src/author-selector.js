/**
 * External dependencies
 */
import React, { PureComponent } from 'react';
import { SelectControl } from '@wordpress/components';

class AuthorSelector extends PureComponent {
	state = {
		isNew: false,
	};

	render() {
		const { importAuthor, siteAuthors } = this.props;
		const { isNew } = this.state;

		const options = [
			{ label: `Create the user "${importAuthor.author_login}"`, value: importAuthor.author_login },
			...siteAuthors.map( author => {
				return {
					label: `Use existing user "${author.name}"`,
					value: author.name,
				};
			} ),
			{ label: 'Create a new user...', value: 0 }
		]

		return (
			<div>
				<SelectControl
					label={ "Map this author's posts to:" }
					options={ options }
				/>
				{ isNew && <label>Enter username: <input type="text" /></label> }
			</div>
		);
	}
}

export default AuthorSelector;
