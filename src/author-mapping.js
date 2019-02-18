/**
 * External dependencies
 */
import React, { PureComponent } from 'react';
import { withState } from '@wordpress/compose';
import { select, withSelect } from '@wordpress/data';
import { SelectControl } from '@wordpress/components';
import { Link } from 'react-router-dom';

function MyAuthorsListBase( { authors } ) {
	return (
		<SelectControl label="Author" options={
			authors.map( author => {
				return {
					label: author.name,
					value: author.id,
				};
			} )
		} />
	);
}

const MyAuthorsList = withSelect( ( select ) => ( {
	authors: select( 'core' ).getAuthors(),
} ) )( MyAuthorsListBase );

class AuthorMapping extends PureComponent {
	render() {
		const attachmentId = select( 'wordpress-importer' ).getAttachmentId();

		return (
			<div>
				<MyAuthorsList />
				{ attachmentId }
				<Link to='/complete'>FINISH!!!</Link>
			</div>
		);
	}
}

export default AuthorMapping;
