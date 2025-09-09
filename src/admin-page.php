<div class="wrap">
    <table class="form-table">
        <tr>
            <th scope="row">Already imported?</th>
            <th scope="row">Categories</th>
        </tr>
        <?php $count = 0; ?>
        <?php foreach ( $this->categories as $category ): ?>
            <?php if ( $count >= 10 ) break; ?>
            <tr>
                <td><?php echo term_exists( $category['category_nicename'], 'category' ) ? 'Yes' : 'No'; ?></td>
                <td><?php echo $category['category_nicename']; ?></td>
            </tr>
            <?php $count++; ?>
        <?php endforeach; ?>
    </table>
    <table class="form-table">
        <tr>
            <th scope="row">Already imported?</th>
            <th scope="row">Tags</th>
        </tr>
        <?php $count = 0; ?>
        <?php foreach ( $this->tags as $tag ): ?>
            <?php if ( $count >= 10 ) break; ?>
            <tr>
                <td><?php echo term_exists( $tag['tag_slug'], 'post_tag' ) ? 'Yes' : 'No'; ?></td>
                <td><?php echo $tag['tag_description']; ?></td>
            </tr>
            <?php $count++; ?>
        <?php endforeach; ?>
    </table>
	<table class="form-table">
		<tr>
			<th scope="row">ID</th>
		</tr>
		<tr>
            <td><?php echo $this->id; ?></td>
        </tr>
	</table>
</div>
