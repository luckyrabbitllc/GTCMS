
var gulp = require('gulp');
var stylus = require('gulp-stylus');
var cssmin = require('gulp-cssmin');
var rename = require('gulp-rename');

gulp.task('frontStyle', function () {
	return gulp.src('public/css/style.styl')
		.pipe(stylus())
		.on('error', function(err){
			console.log(err);
			this.emit('end');
		})
		.pipe(gulp.dest('public/css'))
		.pipe(cssmin())
		.pipe(rename({suffix: '.min'}))
		.pipe(gulp.dest('public/css'));
});

gulp.task('adminStyle', function () {
	return gulp.src('public/gtcms/css/style.styl')
		.pipe(stylus())
		.on('error', function(err){
			console.log(err);
			this.emit('end');
		})
		.pipe(gulp.dest('public/gtcms/css'))
		.pipe(cssmin())
		.pipe(rename({suffix: '.min'}))
		.pipe(gulp.dest('public/gtcms/css'));
});

gulp.task('watch', function() {
	gulp.watch('public/css/style.styl', ['frontStyle']);
	gulp.watch('public/gtcms/css/style.styl', ['adminStyle']);
});

gulp.task('default', ['frontStyle', 'adminStyle']);